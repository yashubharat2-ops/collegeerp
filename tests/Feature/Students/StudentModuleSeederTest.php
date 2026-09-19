<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seeding for the Students module suite.
 *
 * Permissions live in the single centralized DatabaseSeeder (no per-module
 * seeder), so these tests pin two things: `db:seed` is idempotent, and the
 * seeded permissions actually unlock the new modules for a non-super-admin
 * college administrator — i.e. the slugs registered in the seeder are exactly
 * the slugs the policies and controllers ask for.
 */
class StudentModuleSeederTest extends TestCase
{
    use StudentTestHelpers;

    private const NEW_SLUGS = [
        'student_academic_records.view', 'student_academic_records.create',
        'student_academic_records.update', 'student_academic_records.delete',
        'student_documents.view', 'student_documents.create',
        'student_documents.update', 'student_documents.delete',
        'student_id_cards.view', 'student_id_cards.generate',
        'student_promotions.view', 'student_promotions.create', 'student_promotions.approve',
        'student_transfers.view', 'student_transfers.create',
        'student_transfers.update', 'student_transfers.approve',
        'student_history.view',
        // Pre-existing Students module slugs that must not be lost.
        'students.view', 'students.create', 'students.update', 'students.delete',
        'student_enrollments.view', 'student_enrollments.create',
        'student_enrollments.update', 'student_enrollments.delete',
    ];

    public function test_every_student_module_permission_is_seeded_once(): void
    {
        foreach (self::NEW_SLUGS as $slug) {
            $this->assertSame(
                1,
                Permission::query()->where('slug', $slug)->count(),
                "Permission {$slug} must exist exactly once after seeding."
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $before = $this->counts();

        $this->seed();
        $this->seed();

        $this->assertSame($before, $this->counts(), 'Running db:seed again must not duplicate any row.');
    }

    public function test_the_seeded_college_admin_role_holds_every_student_permission(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $granted = $role->permissions()->pluck('slug')->all();

        foreach (self::NEW_SLUGS as $slug) {
            $this->assertContains($slug, $granted, "college-admin must be granted {$slug}.");
        }
    }

    /**
     * End-to-end check of the seeder: a NON-super-admin user holding only the
     * seeded college-admin role can open every module in the STUDENTS nav.
     *
     * This is the test that catches a slug that was typed differently in the
     * seeder and in a policy/controller — hasPermission() reads the permission
     * rows, so a mismatch shows up here as a 403.
     */
    public function test_a_seeded_college_admin_can_open_every_students_module(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded College Admin',
            'email' => 'seeded-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin(), 'This user must exercise the college-scoped permission path.');

        foreach ([
            'students.index',
            'student-enrollments.index',
            'student-academic-records.index',
            'student-documents.index',
            'student-id-cards.index',
            'student-promotions.index',
            'student-transfers.index',
            'student-history.index',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }

        // And the create forms of the modules that have one.
        foreach ([
            'student-enrollments.create',
            'student-academic-records.create',
            'student-documents.create',
            'student-promotions.create',
            'student-transfers.create',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }

    public function test_a_user_without_student_permissions_is_blocked_everywhere(): void
    {
        $college = $this->makeCollege('SEEDNONE');
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);

        foreach ([
            'students.index',
            'student-enrollments.index',
            'student-academic-records.index',
            'student-documents.index',
            'student-id-cards.index',
            'student-promotions.index',
            'student-transfers.index',
            'student-history.index',
        ] as $route) {
            $this->asCollege($college, $nobody)->get(route($route))->assertForbidden();
        }
    }

    /**
     * Snapshot of every row count the seeder can touch.
     */
    private function counts(): array
    {
        return [
            'colleges' => College::query()->count(),
            'permissions' => Permission::query()->count(),
            'roles' => Role::query()->count(),
            'users' => User::query()->count(),
            'permission_role' => DB::table('permission_role')->count(),
            'role_user' => DB::table('role_user')->count(),
            'user_college' => DB::table('user_college')->count(),
        ];
    }
}
