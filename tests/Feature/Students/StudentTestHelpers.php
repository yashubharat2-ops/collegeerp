<?php

namespace Tests\Feature\Students;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentEnrollment;
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

    private function superAdminUser(): User
    {
        return User::create([
            'name' => 'Platform Super',
            'email' => 'super-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function makeYear(College $college, string $code = '2026'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => $code,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
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
