<?php

namespace Tests\Feature\Departments;

use App\Models\{ApprovalSetting, College, Permission, Role, User};
use Illuminate\Support\Str;

trait DepartmentTestHelpers
{
    private function makeCollege(string $code): College
    {
        return College::create(['name' => $code.' College', 'code' => $code, 'slug' => Str::slug($code).'-college', 'status' => 'active']);
    }

    /**
     * Configure one test college for the direct (pre-central-approval)
     * admission-application workflow.
     *
     * Admission approval defaults to ON when no setting row exists. The
     * direct-workflow regression tests below predate that default and assert
     * behaviour of the base CRUD/workflow paths; ApprovalManagementTest
     * separately covers the approval-required path. Disabling the setting per
     * college keeps those assertions exactly as strict as they were, without
     * granting an extra permission that the test is meant to exclude.
     */
    private function disableAdmissionApplicationApproval(College $college): void
    {
        foreach (['application_submit', 'application_status_change'] as $action) {
            ApprovalSetting::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'module' => 'admission',
                'action' => $action,
                'is_required' => false,
                'label' => 'Disabled for direct-workflow regression test',
            ]);
        }
    }

    private function makeUserWithPermissions(College $college, array $slugs, bool $superAdmin = false): User
    {
        $user = User::create([
            'name' => $college->code.($superAdmin ? ' Super' : ' Staff'),
            'email' => strtolower($college->code).'-'.($superAdmin ? 'super' : 'staff').'-'.Str::random(6).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::create(['college_id' => $superAdmin ? null : $college->id, 'name' => $college->code.' Dept Role', 'slug' => 'dept-role-'.strtolower($college->code).'-'.Str::random(4), 'is_system' => false, 'is_active' => true]);
        if ($slugs) {
            // Normalize: flatten one level if caller passed nested array like [pluck()->all()] (common test mistake)
            // This preserves permission semantics while fixing InvalidArgumentException: Nested arrays may not be passed to whereIn
            $normalized = collect($slugs)->flatten()->filter(fn($v) => is_string($v))->values()->all();
            $role->permissions()->sync(Permission::whereIn('slug', $normalized)->pluck('id')->all());
        }
        $user->roles()->attach($role->id, ['college_id' => $superAdmin ? null : $college->id]);

        return $user;
    }

    private function superAdminUser(): User
    {
        return User::create(['name' => 'Platform Super', 'email' => 'super-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
    }

    private function asCollege(College $college, User $user): static
    {
        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }
}
