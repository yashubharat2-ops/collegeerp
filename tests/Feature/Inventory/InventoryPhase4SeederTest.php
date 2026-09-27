<?php

namespace Tests\Feature\Inventory;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\InventoryPhase4PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryPhase4SeederTest extends TestCase
{
    use InventoryTestHelpers;

    public function test_only_five_read_only_permissions_are_seeded_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();

        $this->assertCount(5, InventoryPhase4PermissionSeeder::PERMISSIONS);
        foreach (InventoryPhase4PermissionSeeder::PERMISSIONS as $slug) {
            $permission = Permission::where('slug', $slug)->firstOrFail();
            $this->assertSame(1, Permission::where('slug', $slug)->count());
            $this->assertSame('view', $permission->action);
            $this->assertContains($slug, $admin->permissions()->pluck('slug')->all());
            $this->assertContains($slug, $super->permissions()->pluck('slug')->all());
            $this->assertSame(0, Permission::where('slug', str_replace('.view', '.create', $slug))->count());
        }
        $this->assertSame(0, Permission::where('slug', 'assets.view')->count());
    }

    public function test_the_standalone_seeder_is_idempotent_and_does_not_change_grants(): void
    {
        $before = [Permission::count(), DB::table('permission_role')->count()];

        $this->seed(InventoryPhase4PermissionSeeder::class);
        $this->seed(InventoryPhase4PermissionSeeder::class);
        $this->assertSame($before, [Permission::count(), DB::table('permission_role')->count()]);

        $this->seed();
        $this->assertSame($before, [Permission::count(), DB::table('permission_role')->count()]);
    }
}
