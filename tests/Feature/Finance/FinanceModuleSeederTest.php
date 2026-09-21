<?php

namespace Tests\Feature\Finance;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RBAC seeding contract for the Finance / Fees module.
 *
 * All finance permission slugs live in the single centralized DatabaseSeeder —
 * there is no per-module seeder. A database seeded before the fee slugs were
 * registered silently hides the whole module: every policy check returns false
 * and the sidebar drops the entry, with no error anywhere. These tests pin the
 * contract so that regression can only happen loudly:
 *
 *  - every fee_structures.* slug exists exactly once after `db:seed`;
 *  - previously seeded module permissions were not lost;
 *  - `db:seed` stays idempotent;
 *  - both system roles hold the slugs;
 *  - a SEEDED college-admin (reading permissions from the database) can open the
 *    module and sees the sidebar entry — i.e. the slugs in the seeder are exactly
 *    the slugs the policy and the navigation check ask for.
 */
class FinanceModuleSeederTest extends TestCase
{
    use FeeStructureTestHelpers;

    private const FEE_SLUGS = [
        'fee_structures.view',
        'fee_structures.create',
        'fee_structures.update',
        'fee_structures.delete',
    ];

    private const EARLIER_MODULE_SLUGS = [
        'students.view',
        'academic_years.view',
        'grade_scales.view',
        'marksheets.view',
        'admissions.view',
    ];

    public function test_every_fee_structure_permission_is_seeded_once(): void
    {
        foreach (self::FEE_SLUGS as $slug) {
            $this->assertSame(
                1,
                Permission::query()->where('slug', $slug)->count(),
                "Permission {$slug} must exist exactly once after seeding."
            );
        }
    }

    public function test_previously_seeded_permissions_remain_intact(): void
    {
        foreach (self::EARLIER_MODULE_SLUGS as $slug) {
            $this->assertSame(
                1,
                Permission::query()->where('slug', $slug)->count(),
                "Permission {$slug} must still exist after the Finance seeding."
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

    public function test_seeded_roles_hold_every_fee_structure_permission(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $super = Role::query()->whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        foreach (['super-admin' => $super, 'college-admin' => $admin] as $name => $role) {
            $granted = $role->permissions()->pluck('slug')->all();

            foreach (self::FEE_SLUGS as $slug) {
                $this->assertContains($slug, $granted, "{$name} must be granted {$slug}.");
            }
        }
    }

    public function test_a_seeded_college_admin_can_open_every_fee_structure_screen(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = $this->seededAdmin($college, $role, 'seeded-finance-route-admin@example.test');

        foreach ([
            'fee-structures.index',
            'fee-structures.create',
        ] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }

    public function test_the_seeded_admin_sees_the_finance_section(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = $this->seededAdmin($college, $role, 'seeded-finance-nav-admin@example.test');

        $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finance / Fees')
            ->assertSee(route('fee-structures.index'), false)
            ->assertSee('Fee Structures');
    }

    /**
     * A non-super-admin holding only the seeded college-admin role.
     */
    private function seededAdmin(College $college, Role $role, string $email): User
    {
        $user = User::create([
            'name' => 'Seeded Finance Admin',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin(), 'This user must exercise the college-scoped permission path.');

        return $user;
    }

    /**
     * Snapshot of every row count the seeder can touch.
     *
     * @return array<string, int>
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
