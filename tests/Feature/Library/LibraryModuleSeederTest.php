<?php

namespace Tests\Feature\Library;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Library Management Phase 1 — RBAC seeding.
 *
 * The seventeen library permissions must exist exactly once, be granted to the
 * seeded super-admin and college-admin roles, and re-seeding must be a no-op
 * (idempotent firstOrCreate + sync). Permissions for out-of-scope screens
 * (copies, members, issues, fines, reports) must NOT be seeded yet.
 */
class LibraryModuleSeederTest extends TestCase
{
    use LibraryTestHelpers;

    public function test_library_permissions_are_seeded_once_and_granted_to_both_admin_roles(): void
    {
        $college = College::where('code', 'DEMO')->firstOrFail();
        $admin = Role::where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $super = Role::whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $adminGranted = $admin->permissions()->pluck('slug')->all();
        $superGranted = $super->permissions()->pluck('slug')->all();

        $this->assertCount(17, self::LIBRARY_PERMISSIONS);

        foreach (self::LIBRARY_PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), "Permission {$slug} must be seeded exactly once.");
            $this->assertContains($slug, $adminGranted, "College admin must hold {$slug}.");
            $this->assertContains($slug, $superGranted, "Super admin must hold {$slug}.");
        }

        $permission = Permission::where('slug', 'book_categories.create')->firstOrFail();
        $this->assertSame('book_categories', $permission->module);
        $this->assertSame('create', $permission->action);
    }

    public function test_out_of_scope_library_permissions_are_not_seeded(): void
    {
        foreach (['book_copies.view', 'library_members.view', 'book_issues.view', 'book_returns.view', 'book_renewals.view', 'library_fines.view', 'library_reports.view'] as $slug) {
            $this->assertSame(0, Permission::where('slug', $slug)->count(), "{$slug} belongs to a later phase.");
        }
    }

    public function test_seeding_again_does_not_duplicate_library_permission_or_rbac_rows(): void
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
            'library_permissions' => Permission::whereIn('slug', self::LIBRARY_PERMISSIONS)->count(),
            'roles' => Role::count(),
            'users' => User::count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
