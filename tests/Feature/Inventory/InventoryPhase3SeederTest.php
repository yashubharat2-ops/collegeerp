<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InventoryPhase3PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inventory / Asset Management RBAC seeding — Phase 3.
 *
 * The nine Phase 3 permissions (issue, assignment, return, maintenance)
 * exist exactly once, are granted to the seeded Super Admin and College Admin
 * roles through the centralized seeder, and re-seeding is a no-op. The
 * future reports module and any asset-master permissions are deliberately
 * not seeded.
 */
class InventoryPhase3SeederTest extends TestCase
{
    use InventoryTestHelpers;

    public function test_phase_three_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(9, self::INVENTORY_PHASE3_PERMISSIONS);
        $this->assertSame(self::INVENTORY_PHASE3_PERMISSIONS, InventoryPhase3PermissionSeeder::PERMISSIONS);

        foreach (self::INVENTORY_PHASE3_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'inventory_maintenance.update')->firstOrFail();
        $this->assertSame('inventory_maintenance', $permission->module);
        $this->assertSame('update', $permission->action);
        $this->assertSame(Str::headline('inventory_maintenance.update'), $permission->name);
    }

    public function test_issue_and_assignment_history_stay_append_only_and_reports_are_not_seeded(): void
    {
        // No update / delete anywhere in the Phase 3 families except the
        // live-work maintenance module, and no future module permissions.
        foreach ([
            'inventory_issues.update', 'inventory_issues.delete',
            'inventory_assignments.update', 'inventory_assignments.delete',
            'inventory_asset_returns.update', 'inventory_asset_returns.delete',
            'inventory_maintenance.delete',
            'inventory_reports.view', 'assets.view',
        ] as $slug) {
            $this->assertSame(0, Permission::where('slug', $slug)->count(), "{$slug} must not exist.");
        }
    }

    public function test_seeding_again_does_not_duplicate_permissions_roles_or_grants(): void
    {
        $before = $this->counts();

        $this->seed();
        $this->seed();

        $this->assertSame($before, $this->counts());
    }

    public function test_the_standalone_phase_three_seeder_is_idempotent_and_touches_no_grants(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ];

        $this->seed(InventoryPhase3PermissionSeeder::class);
        $this->seed(InventoryPhase3PermissionSeeder::class);

        $this->assertSame($before, [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'permissions' => Permission::count(),
            'phase3_permissions' => Permission::whereIn('slug', self::INVENTORY_PHASE3_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'colleges' => College::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
