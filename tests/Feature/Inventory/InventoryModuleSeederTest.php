<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inventory / Asset Management RBAC seeding — Phase 1.
 *
 * The thirteen Phase 1 permissions exist exactly once, are granted to the
 * seeded Super Admin and College Admin roles through the centralized seeder,
 * and re-seeding is a no-op. Permissions of the phases that have not been
 * built yet are deliberately not seeded; the Phase 2 (purchase orders and
 * stock) permissions are pinned by InventoryPhase2SeederTest.
 */
class InventoryModuleSeederTest extends TestCase
{
    use InventoryTestHelpers;

    public function test_inventory_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(13, self::INVENTORY_PERMISSIONS);
        $this->assertSame(self::INVENTORY_PERMISSIONS, InventoryPermissionSeeder::PERMISSIONS);

        foreach (self::INVENTORY_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'inventory_items.create')->firstOrFail();
        $this->assertSame('inventory_items', $permission->module);
        $this->assertSame('create', $permission->action);
        $this->assertSame(Str::headline('inventory_items.create'), $permission->name);
    }

    public function test_later_phase_permissions_are_not_seeded(): void
    {
        // Phase 3 (issue / assignment / return / maintenance) is built and
        // seeded — its slugs are pinned by InventoryPhase3SeederTest. What
        // remains unbuilt are the inventory reports and any asset-master
        // permissions, which this module deliberately never introduces.
        foreach ([
            'inventory_reports.view',
            'assets.view',
        ] as $slug) {
            $this->assertSame(0, Permission::where('slug', $slug)->count(), "{$slug} belongs to a later phase.");
        }
    }

    public function test_seeding_again_does_not_duplicate_permissions_roles_or_grants(): void
    {
        $before = $this->counts();

        $this->seed();
        $this->seed();

        $this->assertSame($before, $this->counts());
    }

    public function test_the_standalone_seeder_is_idempotent_and_touches_no_grants(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'permission_role' => DB::table('permission_role')->count(),
        ];

        $this->seed(InventoryPermissionSeeder::class);
        $this->seed(InventoryPermissionSeeder::class);

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
            'inventory_permissions' => Permission::whereIn('slug', self::INVENTORY_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'colleges' => College::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
