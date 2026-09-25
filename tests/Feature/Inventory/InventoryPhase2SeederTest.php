<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InventoryPhase2PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inventory / Asset Management RBAC seeding — Phase 2.
 *
 * The nine Phase 2 permissions exist exactly once, are granted to the seeded
 * Super Admin and College Admin roles through the centralized seeder, and
 * re-seeding is a no-op. Permissions of the phases that are not built yet
 * (issue/return, asset assignment, maintenance, reports) are still not seeded,
 * and stock movements stay immutable (no update / delete permission).
 */
class InventoryPhase2SeederTest extends TestCase
{
    use InventoryTestHelpers;

    public function test_phase_two_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(9, self::INVENTORY_PHASE2_PERMISSIONS);
        $this->assertSame(self::INVENTORY_PHASE2_PERMISSIONS, InventoryPhase2PermissionSeeder::PERMISSIONS);

        foreach (self::INVENTORY_PHASE2_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'inventory_purchase_orders.receive')->firstOrFail();
        $this->assertSame('inventory_purchase_orders', $permission->module);
        $this->assertSame('receive', $permission->action);
        $this->assertSame(Str::headline('inventory_purchase_orders.receive'), $permission->name);
    }

    public function test_stock_movements_stay_immutable_and_later_phases_are_not_seeded(): void
    {
        foreach ([
            'inventory_stock.create', 'inventory_stock.update', 'inventory_stock.delete',
            'inventory_stock_movements.view', 'inventory_stock_movements.delete',
            'inventory_issues.view', 'inventory_assignments.view', 'inventory_maintenance.view',
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

    public function test_the_standalone_phase_two_seeder_is_idempotent_and_touches_no_grants(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ];

        $this->seed(InventoryPhase2PermissionSeeder::class);
        $this->seed(InventoryPhase2PermissionSeeder::class);

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
            'phase2_permissions' => Permission::whereIn('slug', self::INVENTORY_PHASE2_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'colleges' => College::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
