<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicationAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeApplicationContext($college): array
    {
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'first_name' => 'Auth', 'last_name' => 'Test', 'status' => 'active',
        ]);
        $year = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $program = Program::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active',
        ]);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id, 'program_id' => $program->id,
            'application_number' => 'APP-AUTH', 'status' => 'draft',
        ]);

        return [$applicant, $year, $program, $application];
    }

    public function test_guest_cannot_access_applications(): void
    {
        $this->get(route('admission-applications.index'))->assertRedirect(route('login'));
        $this->get(route('admission-applications.create'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $college = $this->makeCollege('AAUT');
        [$applicant, $year, $program, $application] = $this->makeApplicationContext($college);
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)->get(route('admission-applications.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('admission-applications.create'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('admission-applications.store'), [
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
        ])->assertForbidden();
        $this->asCollege($college, $user)->get(route('admission-applications.edit', $application))->assertForbidden();
        $this->asCollege($college, $user)->put(route('admission-applications.update', $application), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'submitted',
        ])->assertForbidden();
        $this->asCollege($college, $user)->delete(route('admission-applications.destroy', $application))->assertForbidden();

        $this->assertDatabaseHas('admission_applications', ['id' => $application->id, 'status' => 'draft', 'deleted_at' => null]);
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('AGRN');
        [$applicant, $year, $program, $application] = $this->makeApplicationContext($college);

        // View-only: index works, every write is forbidden.
        $viewer = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $this->asCollege($college, $viewer)->get(route('admission-applications.index'))->assertOk()->assertSee('APP-AUTH');
        $this->asCollege($college, $viewer)->get(route('admission-applications.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('admission-applications.store'), [
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
        ])->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('admission-applications.edit', $application))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('admission-applications.update', $application), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'submitted',
        ])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('admission-applications.destroy', $application))->assertForbidden();

        // Create permission alone suffices for the create form and the write.
        $creator = $this->makeUserWithPermissions($college, ['admission_applications.create']);
        $this->asCollege($college, $creator)->get(route('admission-applications.create'))->assertOk();
        $this->asCollege($college, $creator)->post(route('admission-applications.store'), [
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
        ], ['Referer' => route('admission-applications.index')])->assertSessionHas('success');

        // Update permission alone suffices for the edit form and the write.
        $editor = $this->makeUserWithPermissions($college, ['admission_applications.update']);
        $this->asCollege($college, $editor)->get(route('admission-applications.edit', $application))->assertOk();
        $this->asCollege($college, $editor)->put(route('admission-applications.update', $application), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'submitted',
        ], ['Referer' => route('admission-applications.edit', $application)])->assertSessionHas('success');
        $this->assertSame('submitted', $application->fresh()->status);

        // Delete permission alone suffices for the soft delete.
        $deleter = $this->makeUserWithPermissions($college, ['admission_applications.delete']);
        $this->asCollege($college, $deleter)->delete(route('admission-applications.destroy', $application), [], ['Referer' => route('admission-applications.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('admission_applications', ['id' => $application->id]);
    }

    public function test_permissions_are_resolved_per_college(): void
    {
        $collegeA = $this->makeCollege('APCA');
        $collegeB = $this->makeCollege('APCB');

        $user = User::create([
            'name' => 'Per College',
            'email' => 'per-college-'.Str::random(6).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($collegeA->id, ['is_default' => true]);
        $user->colleges()->attach($collegeB->id, ['is_default' => false]);

        // Permission granted in college A only.
        $role = Role::create([
            'college_id' => $collegeA->id,
            'name' => 'College A Viewer',
            'slug' => 'college-a-viewer-'.Str::random(4),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', ['admission_applications.view'])->pluck('id')->all());
        $user->roles()->attach($role->id, ['college_id' => $collegeA->id]);

        $this->asCollege($collegeA, $user)->get(route('admission-applications.index'))->assertOk();
        $this->asCollege($collegeB, $user)->get(route('admission-applications.index'))->assertForbidden();
    }

    public function test_super_admin_can_manage_with_explicit_college_context(): void
    {
        $college = $this->makeCollege('ASUP');
        [$applicant, $year, $program] = $this->makeApplicationContext($college);
        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG], ['name' => 'Super Admin', 'is_system' => true]);
        $super->roles()->attach($superRole->id, ['college_id' => null]);
        $super->colleges()->attach($college->id, ['is_default' => true]);

        // The super admin's permissions are global (permission exists + is_active).
        $this->asCollege($college, $super)->get(route('admission-applications.index'))->assertOk();
        $this->asCollege($college, $super)->post(route('admission-applications.store'), [
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'submitted',
        ], ['Referer' => route('admission-applications.index')])->assertSessionHas('success');
        $this->assertDatabaseHas('admission_applications', ['college_id' => $college->id, 'applicant_id' => $applicant->id, 'status' => 'submitted']);

        // Even for a super admin, tenant scoping is honoured: a super admin with no
        // usable college context is rejected at the tenant boundary.
        $homeless = $this->superAdminUser();
        $homeless->roles()->attach($superRole->id, ['college_id' => null]);
        $this->actingAs($homeless)->get(route('admission-applications.index'))->assertForbidden();
    }
}
