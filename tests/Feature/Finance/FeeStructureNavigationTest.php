<?php

namespace Tests\Feature\Finance;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar navigation for the Finance / Fees module.
 *
 * Guards the invariants the module depends on:
 *   - a single "Finance / Fees" section, rendered exactly once;
 *   - it lists the nine module entries, each gated on its own view permission;
 *   - the section disappears entirely when the user holds none of those
 *     permissions (no heading, no links);
 *   - the existing Examinations group keeps its twelve entries, i.e. the finance
 *     section does not leak into another group.
 */
class FeeStructureNavigationTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * Every entry of the group: label => [permission, route].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTRIES = [
        'Fee Structures' => ['fee_structures.view', 'fee-structures.index'],
        'Fee Categories' => ['fee_categories.view', 'fee-categories.index'],
        'Student Fee Assignment' => ['student_fee_assignments.view', 'student-fee-assignments.index'],
        'Fee Collection' => ['fee_collections.view', 'fee-collections.index'],
        'Receipts' => ['receipts.view', 'receipts.index'],
        'Due / Outstanding Fees' => ['fee_dues.view', 'fee-dues.index'],
        'Fee Discounts / Concessions' => ['fee_concessions.view', 'fee-concessions.index'],
        'Refunds' => ['refunds.view', 'refunds.index'],
        'Fee Reports' => ['fee_reports.view', 'fee-reports.index'],
    ];

    /**
     * The Finance / Fees sidebar group: from its heading until the next group
     * heading (same extraction technique as the Students/Examinations tests).
     */
    private function financeNavGroup(string $html): string
    {
        $start = strpos($html, '>Finance / Fees</div>');
        $this->assertNotFalse($start, 'The sidebar must have a Finance / Fees group heading.');

        $after = $start + strlen('>Finance / Fees</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    /**
     * @return array<int, string>
     */
    private function allViewPermissions(): array
    {
        return array_values(array_map(fn (array $entry) => $entry[0], self::ENTRIES));
    }

    public function test_the_finance_section_lists_every_module_entry(): void
    {
        $college = $this->makeCollege('FSNAV1');
        $user = $this->makeUserWithPermissions($college, $this->allViewPermissions());

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Finance / Fees</div>'), 'There must be exactly one Finance / Fees section.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');

        $group = $this->financeNavGroup($html);

        $this->assertSame(count(self::ENTRIES), substr_count($group, 'class="nav-link"'), 'The Finance / Fees group must list every module entry once.');

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString(route($route), $group, "Missing finance entry route: {$label}");
            $this->assertStringContainsString($label, $group, "Missing finance entry label: {$label}");
        }
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('FSNAV2');
        $viewer = $this->makeUserWithPermissions($college, ['receipts.view']);

        $html = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->financeNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'), 'Only the permitted entry may be rendered.');
        $this->assertStringContainsString(route('receipts.index'), $group);
        $this->assertStringNotContainsString(route('fee-structures.index'), $group);
        $this->assertStringNotContainsString(route('fee-collections.index'), $group);
        $this->assertStringNotContainsString(route('refunds.index'), $group);
    }

    public function test_the_section_is_hidden_without_any_finance_permission(): void
    {
        $college = $this->makeCollege('FSNAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'examinations.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Finance / Fees</div>', false)
            ->assertDontSee(route('fee-structures.index'), false)
            ->assertDontSee('Fee Structures');
    }

    public function test_the_section_does_not_disturb_the_examinations_group(): void
    {
        $college = $this->makeCollege('FSNAV4');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        // The Examinations group keeps its twelve entries…
        $start = strpos($html, '>Examinations</div>');
        $this->assertNotFalse($start);

        $after = $start + strlen('>Examinations</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);
        $examinations = substr($html, $after, $end - $after);

        $this->assertSame(12, substr_count($examinations, 'class="nav-link"'));
        $this->assertSame(1, substr_count($html, '>Examinations</div>'));

        // …and the Finance / Fees section sits after it, fully populated.
        $this->assertGreaterThan($start, (int) strpos($html, '>Finance / Fees</div>'));
        $this->assertSame(count(self::ENTRIES), substr_count($this->financeNavGroup($html), 'class="nav-link"'));
    }

    public function test_a_seeded_college_admin_sees_every_finance_entry(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Finance Admin',
            'email' => 'seeded-finance-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $response->assertSee(route($route), false)->assertSee($label);
        }

        foreach (['fee-structures.index', 'fee-categories.index', 'student-fee-assignments.index', 'fee-collections.index', 'fee-dues.index', 'fee-concessions.index', 'refunds.index', 'fee-reports.index'] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
