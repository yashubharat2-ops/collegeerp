<?php

namespace Tests\Feature\Library;

use App\Models\College;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar navigation for the Library Management module.
 *
 * Guards the invariants the module depends on:
 *   - a single "Library Management" section, rendered exactly once;
 *   - it lists exactly the four Phase 1 entries (Library Dashboard, Books,
 *     Book Categories, Authors / Publishers), each gated on its view permission;
 *   - the section disappears entirely when the user holds none of those
 *     permissions (no heading, no links);
 *   - the Finance / Fees group before it keeps its nine entries, i.e. the
 *     library section does not leak into another group.
 */
class LibraryNavigationTest extends TestCase
{
    use LibraryTestHelpers;

    /**
     * Every entry of the group: label => [permission, route].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ENTRIES = [
        'Library Dashboard' => ['library_dashboard.view', 'library.dashboard'],
        'Books' => ['books.view', 'books.index'],
        'Book Categories' => ['book_categories.view', 'book-categories.index'],
        'Authors / Publishers' => ['authors.view', 'authors.index'],
    ];

    /**
     * The Library Management sidebar group: from its heading until the next
     * group heading (same extraction technique as the Finance tests).
     */
    private function libraryNavGroup(string $html): string
    {
        $start = strpos($html, '>Library Management</div>');
        $this->assertNotFalse($start, 'The sidebar must have a Library Management group heading.');

        $after = $start + strlen('>Library Management</div>');
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

    public function test_the_library_section_lists_exactly_the_four_phase_one_entries(): void
    {
        $college = $this->makeCollege('LNAV1');
        $user = $this->makeUserWithPermissions($college, [...$this->allViewPermissions(), 'publishers.view']);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Library Management</div>'), 'There must be exactly one Library Management section.');
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');

        $group = $this->libraryNavGroup($html);

        $this->assertSame(4, substr_count($group, 'class="nav-link"'), 'The Library Management group must list exactly the four Phase 1 entries.');

        foreach (self::ENTRIES as $label => [$permission, $route]) {
            $this->assertStringContainsString(route($route), $group, "Missing library entry route: {$label}");
            $this->assertStringContainsString($label, $group, "Missing library entry label: {$label}");
        }

        // Out-of-scope screens must not be advertised anywhere in the sidebar.
        foreach (['Book Copies', 'Library Members', 'Issue / Return', 'Renewals', 'Fines', 'Library Reports'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_each_entry_is_gated_on_its_own_view_permission(): void
    {
        $college = $this->makeCollege('LNAV2');
        $viewer = $this->makeUserWithPermissions($college, ['book_categories.view']);

        $html = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->libraryNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'), 'Only the permitted entry may be rendered.');
        $this->assertStringContainsString(route('book-categories.index'), $group);
        $this->assertStringNotContainsString(route('library.dashboard'), $group);
        $this->assertStringNotContainsString(route('books.index'), $group);
        $this->assertStringNotContainsString(route('authors.index'), $group);
        $this->assertStringNotContainsString(route('publishers.index'), $group);
    }

    public function test_the_authors_publishers_entry_falls_back_to_publishers_when_only_that_permission_is_held(): void
    {
        $college = $this->makeCollege('LNAV3');
        $publishersOnly = $this->makeUserWithPermissions($college, ['publishers.view']);

        $html = $this->asCollege($college, $publishersOnly)->get(route('dashboard'))->assertOk()->getContent();
        $group = $this->libraryNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'));
        $this->assertStringContainsString('Authors / Publishers', $group);
        $this->assertStringContainsString(route('publishers.index'), $group);
        $this->assertStringNotContainsString(route('authors.index'), $group);
    }

    public function test_the_section_is_hidden_without_any_library_permission(): void
    {
        $college = $this->makeCollege('LNAV4');
        $stranger = $this->makeUserWithPermissions($college, ['students.view', 'fee_categories.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Library Management</div>', false)
            ->assertDontSee(route('library.dashboard'), false)
            ->assertDontSee(route('books.index'), false)
            ->assertDontSee('Library Dashboard')
            ->assertDontSee('Book Categories');
    }

    public function test_the_section_does_not_disturb_the_finance_group(): void
    {
        $college = $this->makeCollege('LNAV5');
        $super = $this->makeSuperAdmin($college);

        $html = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk()->getContent();

        // The Finance / Fees group keeps its nine entries…
        $start = strpos($html, '>Finance / Fees</div>');
        $this->assertNotFalse($start);

        $after = $start + strlen('>Finance / Fees</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);
        $finance = substr($html, $after, $end - $after);

        $this->assertSame(9, substr_count($finance, 'class="nav-link"'));
        $this->assertSame(1, substr_count($html, '>Finance / Fees</div>'));

        // …the Library Management section sits after it, fully populated…
        $libraryStart = (int) strpos($html, '>Library Management</div>');
        $this->assertGreaterThan($start, $libraryStart);
        $this->assertSame(4, substr_count($this->libraryNavGroup($html), 'class="nav-link"'));

        // …and Platform / Settings still closes the sidebar after it.
        $this->assertGreaterThan($libraryStart, (int) strrpos($html, '>Platform</div>'));
    }

    public function test_a_seeded_college_admin_sees_every_library_entry_and_can_open_each_screen(): void
    {
        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $role = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $user = User::create([
            'name' => 'Seeded Library Admin',
            'email' => 'seeded-library-admin@example.test',
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

        foreach (['library.dashboard', 'books.index', 'books.create', 'book-categories.index', 'book-categories.create', 'authors.index', 'authors.create', 'publishers.index', 'publishers.create'] as $route) {
            $this->asCollege($college, $user)->get(route($route))->assertOk();
        }
    }
}
