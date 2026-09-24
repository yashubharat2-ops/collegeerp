<?php

namespace Tests\Feature\Hostel;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hostel Management RBAC seeding.
 *
 * Hostel permissions must exist exactly once, be granted to the seeded
 * super-admin and college-admin roles, and re-seeding must be a no-op. Phase 2
 * and Phase 3 permission slugs are deliberately not seeded yet.
 */
class HostelModuleSeederTest extends TestCase
{
    use HostelTestHelpers;

    public function test_hostel_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(17, self::HOSTEL_PERMISSIONS);

        foreach (self::HOSTEL_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'hostel_rooms.create')->firstOrFail();
        $this->assertSame('hostel_rooms', $permission->module);
        $this->assertSame('create', $permission->action);
    }

    public function test_out_of_scope_hostel_permissions_are_not_seeded(): void
    {
        foreach ([
            'hostel_allocations.view',
            'hostel_fees.view',
            'hostel_attendance.view',
            'hostel_visitors.view',
            'hostel_reports.view',
        ] as $slug) {
            $this->assertSame(0, Permission::where('slug', $slug)->count(), "{$slug} is not part of this phase.");
        }
    }

    public function test_seeding_again_does_not_duplicate_hostel_permission_or_rbac_rows(): void
    {
        $before = $this->counts();
        $this->seed();
        $this->seed();
        $this->assertSame($before, $this->counts());
    }

    public function test_the_standalone_hostel_permission_seeder_is_idempotent_and_touches_no_grants(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ];

        $this->seed(\Database\Seeders\HostelPermissionSeeder::class);
        $this->seed(\Database\Seeders\HostelPermissionSeeder::class);

        $this->assertSame($before, [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ]);
    }

    private function counts(): array
    {
        return [
            'permissions' => Permission::count(),
            'hostel_permissions' => Permission::whereIn('slug', self::HOSTEL_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
