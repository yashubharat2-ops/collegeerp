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
 * Phase 2 permissions exist exactly once, are granted to the seeded Super
 * Admin and College Admin roles, and re-seeding is a no-op. Later-phase grants
 * have their own tests; stock movements stay immutable (no update/delete).
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

        $this->assertCount(14, self::INVENTORY_PHASE2_PERMISSIONS);
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

    public function test_stock_movements_stay_immutable_without_an_asset_master_permission(): void
    {
        // Phase 3/4 permissions are pinned by their own seeder tests.
        foreach ([
            'inventory_stock.create', 'inventory_stock.update', 'inventory_stock.delete',
            'inventory_stock_movements.view', 'inventory_stock_movements.delete',
            'assets.view',
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
