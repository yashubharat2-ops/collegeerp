<?php

namespace Tests\Feature\ExamAttendance;

use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the Examinations Phase 2 tests (Exam Attendance and
 * Marks Entry). Mirrors the DepartmentTestHelpers / StudentTestHelpers
 * conventions used across the project.
 */
trait ExamAttendanceTestHelpers
{
    private function makeCollege(string $code): College
    {
        return College::create(['name' => $code.' College', 'code' => $code, 'slug' => Str::slug($code).'-college', 'status' => 'active']);
    }

    private function makeUserWithPermissions(College $college, array $slugs): User
    {
        $user = User::create([
            'name' => $college->code.' Exam Staff',
            'email' => strtolower($college->code).'-exam-'.Str::random(6).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::create([
            'college_id' => $college->id,
            'name' => $college->code.' Exam Role',
            'slug' => 'exam-role-'.strtolower($college->code).'-'.Str::random(4),
            'is_system' => false,
            'is_active' => true,
        ]);
        if ($slugs) {
            $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->all());
        }
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        return $user;
    }

    private function superAdminUser(): User
    {
        return User::create(['name' => 'Platform Super', 'email' => 'super-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
    }

    private function makeSuperAdmin(College $college): User
    {
        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(
            ['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $super->roles()->syncWithoutDetaching([$superRole->id => ['college_id' => null]]);
        $super->colleges()->syncWithoutDetaching([$college->id => ['is_default' => true]]);

        return $super;
    }

    private function asCollege(College $college, User $user): static
    {
        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }

    /**
     * Year / term / program / section / subject / examination / schedule
     * fixture for one college.
     */
    private function makeExamContext(College $college, string $prefix, array $scheduleOverrides = []): array
    {
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => "2026 {$prefix}", 'code' => "AY-{$prefix}", 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => "Term {$prefix}", 'code' => "T-{$prefix}", 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $prog = Program::create(['college_id' => $college->id, 'name' => "Prog {$prefix}", 'code' => "P-{$prefix}", 'status' => 'active']);
        $sec = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'name' => "Sec {$prefix}", 'code' => "S-{$prefix}", 'status' => 'active']);
        $sub = Subject::create(['college_id' => $college->id, 'name' => "Sub {$prefix}", 'code' => "SB-{$prefix}", 'status' => 'active']);
        $exam = Examination::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'name' => "Exam {$prefix}", 'code' => "E-{$prefix}", 'exam_type' => 'Internal', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'status' => 'published']);

        $schedule = ExamSchedule::create(array_merge([
            'college_id' => $college->id,
            'examination_id' => $exam->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $prog->id,
            'section_id' => $sec->id,
            'subject_id' => $sub->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => ExamSchedule::STATUS_SCHEDULED,
        ], $scheduleOverrides));

        return compact('year', 'term', 'prog', 'sec', 'sub', 'exam', 'schedule');
    }

    /**
     * A student with an active enrollment inside the fixture section/program.
     *
     * @return array{0: Student, 1: StudentEnrollment}
     */
    private function makeEnrolledStudent(College $college, array $ctx, string $suffix, array $enrollmentOverrides = []): array
    {
        $student = Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-'.$suffix.'-'.Str::upper(Str::random(4)),
            'first_name' => 'Stu',
            'last_name' => $suffix,
            'status' => 'active',
        ]);

        $enrollment = StudentEnrollment::create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'section_id' => $ctx['sec']->id,
            'enrollment_number' => 'ENR-'.$suffix.'-'.Str::upper(Str::random(4)),
            'enrollment_date' => '2026-08-05',
            'status' => 'active',
        ], $enrollmentOverrides));

        return [$student, $enrollment];
    }

    /**
     * An active Academics subject enrollment for the fixture subject/term —
     * the module Exam Attendance and Marks Entry reuse for subject-level
     * eligibility.
     */
    private function makeSubjectEnrollment(College $college, array $ctx, StudentEnrollment $enrollment, string $status = 'active'): AcademicSubjectEnrollment
    {
        return AcademicSubjectEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $enrollment->student_id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['prog']->id,
            'section_id' => $ctx['sec']->id,
            'subject_id' => $ctx['sub']->id,
            'status' => $status,
            'enrollment_date' => '2026-08-06',
        ]);
    }
}
