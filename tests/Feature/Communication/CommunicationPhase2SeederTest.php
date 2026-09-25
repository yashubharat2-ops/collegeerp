<?php

namespace Tests\Feature\Communication;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CommunicationPhase2PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Communication Management RBAC seeding — Phase 2.
 *
 * The seven Phase 2 permissions exist exactly once, are granted to the
 * seeded Super Admin and College Admin roles through the centralized seeder,
 * and re-seeding is a no-op. Permissions of later phases (external SMS /
 * e-mail / WhatsApp gateways) are still not seeded, and logs stay read-only
 * (no create / update / delete permission).
 */
class CommunicationPhase2SeederTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_phase_two_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(7, self::COMMUNICATION_PHASE2_PERMISSIONS);
        $this->assertSame(self::COMMUNICATION_PHASE2_PERMISSIONS, CommunicationPhase2PermissionSeeder::PERMISSIONS);

        foreach (self::COMMUNICATION_PHASE2_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'communication_templates.create')->firstOrFail();
        $this->assertSame('communication_templates', $permission->module);
        $this->assertSame('create', $permission->action);
        $this->assertSame(Str::headline('communication_templates.create'), $permission->name);
    }

    public function test_write_permissions_for_logs_tracking_and_reports_are_not_seeded(): void
    {
        foreach ([
            'communication_logs.create', 'communication_logs.update', 'communication_logs.delete',
            'communication_tracking.update', 'communication_reports.create',
            'sms_gateway.view', 'email_gateway.view', 'whatsapp.view', 'sms.send', 'email.send',
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

        $this->seed(CommunicationPhase2PermissionSeeder::class);
        $this->seed(CommunicationPhase2PermissionSeeder::class);

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
            'phase2_permissions' => Permission::whereIn('slug', self::COMMUNICATION_PHASE2_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'colleges' => College::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
