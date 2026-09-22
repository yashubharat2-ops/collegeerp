<?php

namespace Tests\Feature\HR;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HRModuleSeederTest extends TestCase
{
    private const PERMISSIONS = [
        'employees.view', 'employees.create', 'employees.update', 'employees.delete',
        'designations.view', 'designations.create', 'designations.update', 'designations.delete',
        'employee_documents.view', 'employee_documents.create', 'employee_documents.update', 'employee_documents.delete',
    ];

    public function test_hr_permissions_are_seeded_once_and_granted_to_the_college_admin(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $role = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $granted = $role->permissions()->pluck('slug')->all();

        foreach (self::PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count());
            $this->assertContains($slug, $granted);
        }
    }

    public function test_seeding_again_does_not_duplicate_hr_permission_or_rbac_rows(): void
    {
        $before = $this->counts();
        $this->seed();
        $this->seed();
        $this->assertSame($before, $this->counts());
    }

    private function counts(): array
    {
        return [
            'permissions' => Permission::count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
