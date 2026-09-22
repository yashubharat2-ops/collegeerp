<?php

namespace Tests\Feature\HR;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HRModuleSeederTest extends TestCase
{
    private const PERMISSIONS = [
        'faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete',
        'designations.view', 'designations.create', 'designations.update', 'designations.delete',
        'employee_documents.view', 'employee_documents.create', 'employee_documents.update', 'employee_documents.delete',
        'staff_attendance.view', 'staff_attendance.create', 'staff_attendance.update', 'staff_attendance.delete',
        'leave_types.view', 'leave_types.create', 'leave_types.update', 'leave_types.delete',
        'leave_requests.view', 'leave_requests.create', 'leave_requests.update', 'leave_requests.delete', 'leave_requests.approve',
        'salary_structures.view', 'salary_structures.create', 'salary_structures.update', 'salary_structures.delete',
        'salary_components.view', 'salary_components.create', 'salary_components.update', 'salary_components.delete',
        'payrolls.view', 'payrolls.create', 'payrolls.process', 'payrolls.update', 'payrolls.delete', 'hr_reports.view',
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
        $this->assertSame(0, Permission::where('slug', 'employees.view')->count());
        $this->assertSame(0, Permission::where('slug', 'employees.create')->count());
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
