<?php

namespace Tests\Feature\Communication;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CommunicationPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Communication Management RBAC seeding — Phase 1.
 *
 * The fifteen permissions exist exactly once, are granted to the seeded
 * Super Admin and College Admin roles through the centralized seeder, and
 * re-seeding is a no-op (no duplicate permissions, roles, grants or users).
 * Future-phase permissions are deliberately not seeded.
 */
class CommunicationSeederTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_communication_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(15, self::COMMUNICATION_PERMISSIONS);
        $this->assertSame(self::COMMUNICATION_PERMISSIONS, CommunicationPermissionSeeder::PERMISSIONS);

        foreach (self::COMMUNICATION_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'notices.publish')->firstOrFail();
        $this->assertSame('notices', $permission->module);
        $this->assertSame('publish', $permission->action);
        // Same naming convention as every other module's permissions.
        $this->assertSame(Str::headline('notices.publish'), $permission->name);
    }

    public function test_future_phase_permissions_are_not_seeded(): void
    {
        foreach ([
            'sms.view', 'sms_gateway.view', 'email_gateway.view', 'whatsapp.view',
            'sms_templates.view', 'email_templates.view', 'communication_templates.view',
            'delivery_logs.view', 'communication_reports.view',
        ] as $slug) {
            $this->assertSame(0, Permission::where('slug', $slug)->count(), "{$slug} belongs to a future phase.");
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

        $this->seed(CommunicationPermissionSeeder::class);
        $this->seed(CommunicationPermissionSeeder::class);

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
            'communication_permissions' => Permission::whereIn('slug', self::COMMUNICATION_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'colleges' => College::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
