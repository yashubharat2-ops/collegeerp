<?php

namespace Tests\Feature\Students;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocumentType;
use App\Models\Campus;
use App\Models\College;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use App\Models\StudentTransfer;
use App\Models\User;
use Illuminate\Support\Str;

trait StudentTestHelpers
{
    private function makeCollege(string $code): College
    {
        return College::create(['name' => $code.' College', 'code' => $code, 'slug' => Str::slug($code).'-college', 'status' => 'active']);
    }

    private function makeUserWithPermissions(College $college, array $slugs): User
    {
        $user = User::create([
            'name' => $college->code.' Student Staff',
            'email' => strtolower($college->code).'-student-'.Str::random(6).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::create([
            'college_id' => $college->id,
            'name' => $college->code.' Student Role',
            'slug' => 'student-role-'.strtolower($college->code).'-'.Str::random(4),
            'is_system' => false,
            'is_active' => true,
        ]);
        if ($slugs) {
            $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->all());
        }
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        return $user;
    }

    private function asCollege(College $college, User $user): static
    {
        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }

    private function makeYear(College $college, string $code = '2026', string $name = '2026-27', string $startsOn = '2026-06-01', string $endsOn = '2027-05-31'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => $name,
            'code' => $code,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'active',
        ]);
    }

    /**
     * A following academic year, for promotion target assertions.
     */
    private function makeNextYear(College $college, string $code = '2027'): AcademicYear
    {
        return $this->makeYear($college, $code, '2027-28', '2027-06-01', '2028-05-31');
    }

    private function makeAcademicTerm(College $college, AcademicYear $year, string $code = 'SEM1', string $name = 'Semester 1', int $sequence = 1): AcademicTerm
    {
        return AcademicTerm::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => $name,
            'code' => $code,
            'type' => 'semester',
            'sequence' => $sequence,
            'status' => 'active',
        ]);
    }

    private function makeSection(College $college, AcademicYear $year, Program $program, string $code = 'A', ?Campus $campus = null): Section
    {
        return Section::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'campus_id' => $campus?->id,
            'name' => 'Section '.$code,
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeCampus(College $college, string $code = 'MAIN'): Campus
    {
        return Campus::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => $code.' Campus',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    /**
     * Document types are the Admissions module's master data, reused by student
     * documents — the helper makes that reuse explicit in tests.
     */
    private function makeDocumentType(College $college, string $code = 'MARKSHEET', int $maxSizeKb = 5120, ?string $extensions = 'pdf,jpg,jpeg,png'): AdmissionDocumentType
    {
        return AdmissionDocumentType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => $code,
            'name' => ucfirst(strtolower($code)),
            'is_required' => false,
            'allowed_extensions' => $extensions,
            'max_size_kb' => $maxSizeKb,
            'status' => 'active',
        ]);
    }

    private function makeAcademicRecord(College $college, Student $student, AcademicYear $year, array $overrides = []): StudentAcademicRecord
    {
        return StudentAcademicRecord::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'academic_status' => 'enrolled',
            'promotion_status' => 'not_applicable',
            'completion_status' => 'pending',
        ], $overrides));
    }

    private function makeTransfer(College $college, Student $student, array $overrides = []): StudentTransfer
    {
        return StudentTransfer::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'transfer_date' => now()->toDateString(),
            'reason' => 'Relocation of family',
            'status' => 'pending',
            'tc_status' => 'pending',
        ], $overrides));
    }

    private function makeProgram(College $college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeStudent(College $college, array $overrides = []): Student
    {
        return Student::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'student_number' => 'STU-'.Str::upper(Str::random(6)),
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'status' => 'active',
        ], $overrides));
    }

    private function makeEnrollment(College $college, Student $student, AcademicYear $year, ?Program $program = null, array $overrides = []): StudentEnrollment
    {
        return StudentEnrollment::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program?->id,
            'enrollment_number' => 'ENR-'.Str::upper(Str::random(6)),
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ], $overrides));
    }

    private function makeApprovedApplication(College $college, AcademicYear $year, ?Program $program = null, string $status = 'approved'): AdmissionApplication
    {
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Admit',
            'last_name' => 'Candidate',
            'email' => strtolower($college->code).'-candidate@example.test',
            'status' => 'active',
        ]);

        return AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program?->id,
            'application_number' => 'APP-'.Str::upper(Str::random(6)),
            'status' => $status,
        ]);
    }
}
