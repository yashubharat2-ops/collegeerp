<?php

namespace Tests\Feature\Examinations;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

/**
 * RBAC seeding contract for the Examinations module (Phases 1–3).
 *
 * All examination permission slugs — including the Phase 3 groups (Results,
 * Result Calculation, Grade / Pass-Fail, Result Publishing) — live in the
 * single centralized DatabaseSeeder; there is no per-module seeder.
 *
 * A database seeded before the Phase 3 slugs were registered silently hides
 * the whole Phase 3 surface: every policy check returns false and the sidebar
 * drops the menu items, with no error anywhere. These tests pin the contract
 * so that regression can only happen loudly:
 *
 *  - every Phase 3 slug exists exactly once after `db:seed`;
 *  - the Phase 1/2 examination permissions were not lost when Phase 3 landed;
 *  - `db:seed` stays idempotent;
 *  - the roles the seeder grants examination permissions to (super-admin and
 *    the seeded college-admin) hold the Phase 3 slugs under the SAME
 *    convention as the Phase 1/2 examination slugs;
 *  - a SEEDED college-admin (reading permissions from the database, not a
 *    synthetic permission holder) can open every Examinations screen and sees
 *    the Phase 3 menu items — i.e. the slugs in the seeder are exactly the
 *    slugs the policies and navigation checks ask for.
 */
class ExaminationsModuleSeederTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    /** The Phase 3 slugs, in the order they are registered in the seeder. */
    private const PHASE3_SLUGS = [
        'results.view', 'results.view_unpublished',
        'result_calculation.view', 'result_calculation.calculate', 'result_calculation.recalculate',
        'grade_scales.view', 'grade_scales.create', 'grade_scales.update', 'grade_scales.delete',
        'result_publishing.view', 'result_publishing.publish', 'result_publishing.unpublish',
    ];

    /** The Phase 1/2 examination slugs that must never be lost. */
    private const PHASE12_SLUGS = [
        'examinations.view', 'examinations.create', 'examinations.update', 'examinations.delete',
        'exam_schedules.view', 'exam_schedules.create', 'exam_schedules.update', 'exam_schedules.delete',
        'exam_attendance.view', 'exam_attendance.create', 'exam_attendance.update', 'exam_attendance.delete',
        'exam_marks.view', 'exam_marks.create', 'exam_marks.update', 'exam_marks.delete',
    ];

    public function test_every_phase_three_permission_is_seeded_once(): void
    {
        foreach (self::PHASE3_SLUGS as $slug) {
            $this->assertSame(
                1,
                Permission::query()->where('slug', $slug)->count(),
                "Permission {$slug} must exist exactly once after seeding."
            );
        }
    }

    public function test_examination_phase_one_and_two_permissions_remain_intact(): void
    {
        foreach (self::PHASE12_SLUGS as $slug) {
            $this->assertSame(
                1,
                Permission::query()->where('slug', $slug)->count(),
                "Permission {$slug} must still exist after the Phase 3 seeding."
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

    public function test_seeded_roles_hold_every_examination_permission(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $super = Role::query()->whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        // The seeder grants the Phase 3 groups exactly like the Phase 1/2
        // examination groups: to BOTH system roles, no exceptions.
        foreach (['super-admin' => $super, 'college-admin' => $admin] as $name => $role) {
            $granted = $role->permissions()->pluck('slug')->all();

            foreach ([...self::PHASE12_SLUGS, ...self::PHASE3_SLUGS] as $slug) {
                $this->assertContains($slug, $granted, "{$name} must be granted {$slug}.");
            }
        }
    }

    /**
     * End-to-end check of the seeder: a NON-super-admin user holding only the
     * seeded college-admin role can open every Examinations screen, including
     * all four Phase 3 screens.
     *
     * Like the Students equivalent, this catches a slug typed differently in
     * the seeder than in a policy/controller — hasPermission() reads the
     * permission rows, so a mismatch shows up here as a 403.
     */
    public function test_a_seeded_college_admin_can_open_every_examinations_module(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Examinations Admin',
            'email' => 'seeded-exams-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin(), 'This user must exercise the college-scoped permission path.');

        foreach ([
            // Phase 1/2.
            'examinations.index',
            'exam-schedules.index',
            'exam-attendance.index',
            'exam-marks.index',
            // Phase 3.
            'results.index',
            'result-calculation.index',
            'grade-scales.index',
            'result-publishing.index',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }

    /**
     * The sidebar exposes the Phase 3 menu items for a seeded college-admin
     * precisely because the seeded slugs match the navigation permission
     * checks in the layout.
     */
    public function test_sidebar_shows_phase_three_menu_items_for_a_seeded_college_admin(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Examinations Admin',
            'email' => 'seeded-exams-admin-nav@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('results.index'), false)
            ->assertSee('Results')
            ->assertSee(route('result-calculation.index'), false)
            ->assertSee('Result Calculation')
            ->assertSee(route('grade-scales.index'), false)
            ->assertSee('Grade / Pass-Fail')
            ->assertSee(route('result-publishing.index'), false)
            ->assertSee('Result Publishing');
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
