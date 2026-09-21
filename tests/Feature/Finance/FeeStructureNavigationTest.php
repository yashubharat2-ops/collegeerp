<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;

/**
 * Sidebar navigation for the Finance / Fees module.
 *
 * Guards the invariants the module depends on:
 *   - a NEW "Finance / Fees" section, rendered exactly once;
 *   - it holds exactly ONE entry — Fee Structures — and no placeholder for the
 *     deferred phases (collection, receipts, discounts, refunds, reports);
 *   - the entry is gated on fee_structures.view;
 *   - the existing Examinations group keeps its twelve entries, i.e. the new
 *     section does not leak into another group.
 */
class FeeStructureNavigationTest extends TestCase
{
    use FeeStructureTestHelpers;

    /** Finance / Fees phases that must NOT appear anywhere in the sidebar yet. */
    private const FUTURE_ITEMS = [
        'Fee Collection',
        'Fee Receipts',
        'Receipts',
        'Discounts',
        'Refunds',
        'Fee Reports',
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

    public function test_the_finance_section_holds_exactly_one_entry(): void
    {
        $college = $this->makeCollege('FSNAV1');
        $user = $this->makeUserWithPermissions($college, ['fee_structures.view']);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Finance / Fees</div>'), 'There must be exactly one Finance / Fees section.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');

        $group = $this->financeNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'), 'The Finance / Fees group must contain exactly one entry.');
        $this->assertStringContainsString(route('fee-structures.index'), $group);
        $this->assertStringContainsString('Fee Structures', $group);

        foreach (self::FUTURE_ITEMS as $future) {
            $this->assertStringNotContainsString($future, $html, "Deferred fee module must not appear: {$future}");
        }
    }

    public function test_the_entry_is_hidden_without_the_view_permission(): void
    {
        $college = $this->makeCollege('FSNAV2');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Finance / Fees</div>', false)
            ->assertDontSee(route('fee-structures.index'), false)
            ->assertDontSee('Fee Structures');
    }

    public function test_the_section_does_not_disturb_the_examinations_group(): void
    {
        $college = $this->makeCollege('FSNAV3');
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

        // …and the Finance / Fees section sits after it, holding one entry.
        $this->assertGreaterThan($start, (int) strpos($html, '>Finance / Fees</div>'));
        $this->assertSame(1, substr_count($this->financeNavGroup($html), 'class="nav-link"'));
    }

    public function test_a_seeded_college_admin_sees_the_finance_entry(): void
    {
        $college = \App\Models\College::query()->where('code', 'DEMO')->firstOrFail();
        $role = \App\Models\Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = \App\Models\User::create([
            'name' => 'Seeded Finance Admin',
            'email' => 'seeded-finance-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        $this->assertFalse($user->isSuperAdmin());

        $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('fee-structures.index'), false)
            ->assertSee('Fee Structures');

        $this->asCollege($college, $user)->get(route('fee-structures.index'))->assertOk();
    }
}
