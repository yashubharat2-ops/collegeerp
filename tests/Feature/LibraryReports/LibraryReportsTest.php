<?php

namespace Tests\Feature\LibraryReports;

use App\Http\Controllers\LibraryReportController;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Library\LibraryTestHelpers;
use Tests\TestCase;

/**
 * Library Reports — the read-only reporting layer over the existing Library
 * Management module (books, book categories, authors, publishers, book copies,
 * library members, issue / return, renewals and fines).
 *
 * Covers: the single library_reports.view permission and its RBAC independence
 * from the operational Library permissions, the sidebar placement under REPORTS
 * after HR Reports, the exact eight report views and their order, tenant
 * isolation including forged foreign filter ids, the book / category / author /
 * publisher relationships, copies with their derived availability, member
 * relationships, issue / return and renewal data, the existing overdue
 * definition, the stored fine amounts (never recalculated), lost / damaged
 * copies, the Library Summary, empty + invalid + irrelevant filters, soft
 * deletes, deterministic pagination, GET-only routes with no writes, and query
 * counts that never grow with row counts (N+1 protection).
 */
class LibraryReportsTest extends TestCase
{
    use LibraryTestHelpers;

    private const VIEW = ['library_reports.view'];

    /** The operational Library permissions that must NOT grant any report. */
    private const OPERATIONAL = [
        'books.view', 'book_categories.view', 'authors.view', 'book_copies.view',
        'library_members.view', 'library_transactions.view', 'library_renewals.view',
        'library_fines.view', 'library_dashboard.view',
    ];

    /** Core Library tables the read-only reports must never touch. */
    private const TABLES = [
        'books', 'book_copies', 'book_categories', 'authors', 'publishers',
        'library_members', 'library_transactions', 'library_renewals', 'library_fines',
    ];

    /* ------------------------------------------------------------------ *\
     * Permission / RBAC
     * \* ------------------------------------------------------------------ */

    public function test_library_reports_permission_is_separate_from_the_operational_library_permissions(): void
    {
        $college = $this->makeCollege('LIBRPERM');

        // Guests are redirected to login for the report route.
        $this->get(route('library-reports.index'))->assertRedirect(route('login'));

        // Every operational Library permission together still does NOT open the reports.
        $operator = $this->makeUserWithPermissions($college, self::OPERATIONAL);
        foreach (array_keys(LibraryReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)
                ->get(route('library-reports.index', ['report' => $report]))->assertForbidden();
        }
        $this->asCollege($college, $operator)->get(route('books.index'))->assertOk()
            ->assertDontSee('href="'.route('library-reports.index').'"', false)
            ->assertDontSee('>REPORTS<', false);

        // The report permission alone opens every report but no operational page.
        $reporter = $this->reporter($college);
        foreach (array_keys(LibraryReportController::REPORTS) as $report) {
            $this->get(route('library-reports.index', ['report' => $report]))
                ->assertOk()->assertSee(LibraryReportController::REPORTS[$report]);
        }
        $this->get(route('books.index'))->assertForbidden();
        $this->get(route('library-transactions.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('LIBRPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('library-reports.index'))->assertForbidden();
    }

    public function test_the_seeded_admin_roles_hold_the_library_reports_permission(): void
    {
        // The existing seeding system creates the dedicated permission and
        // grants it to both admin roles; it stays separate from the
        // operational Library permissions.
        $permission = Permission::query()->where('slug', 'library_reports.view')->firstOrFail();
        $this->assertSame('library_reports', $permission->module);

        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $super = Role::query()->whereNull('college_id')->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();

        $this->assertTrue($super->permissions()->whereKey($permission->id)->exists(), 'Super Admin must hold library_reports.view.');
        $this->assertTrue($admin->permissions()->whereKey($permission->id)->exists(), 'College Admin must hold library_reports.view.');

        // A report permission is not an operational Library grant.
        foreach (self::OPERATIONAL as $slug) {
            $this->assertNotSame('library_reports.view', $slug);
        }
    }

    /* ------------------------------------------------------------------ *\
     * The exact eight reports, their order and their filters
     * \* ------------------------------------------------------------------ */

    public function test_exactly_eight_reports_render_in_the_fixed_order_with_only_relevant_filters(): void
    {
        $college = $this->makeCollege('LIBRLIST');
        $this->reporter($college);

        $expected = [
            'books' => 'Books Report',
            'copies' => 'Book Copies Report',
            'members' => 'Library Members Report',
            'circulation' => 'Issue / Return Report',
            'overdue' => 'Overdue Books Report',
            'fines' => 'Fine Report',
            'lost_damaged' => 'Lost / Damaged Books Report',
            'summary' => 'Library Summary',
        ];
        $this->assertSame($expected, LibraryReportController::REPORTS, 'The eight Library reports keep their exact names and order.');

        // Every report renders with its own label and only its own filters.
        foreach (LibraryReportController::REPORTS as $key => $label) {
            $this->get(route('library-reports.index', ['report' => $key]))
                ->assertOk()
                ->assertViewHas('report', $key)
                ->assertViewHas('visible', LibraryReportController::FILTERS[$key])
                ->assertSee($label);
        }

        // The report switcher shows exactly the eight entries in the fixed order.
        $html = $this->get(route('library-reports.index'))->assertOk()->getContent();
        $navStart = strpos($html, 'aria-label="Library report views"');
        $this->assertNotFalse($navStart, 'The report view navigation must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(8, substr_count($nav, 'href='));
        $previous = -1;
        foreach ($expected as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The nav must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }

        // Reports only carry the filters they can use.
        $this->assertSame(['search', 'book_category_id', 'author_id', 'publisher_id', 'status'], LibraryReportController::FILTERS['books']);
        $this->assertSame(['search', 'book_id', 'status', 'availability'], LibraryReportController::FILTERS['copies']);
        $this->assertSame(['search', 'student_enrollment_id', 'status'], LibraryReportController::FILTERS['members']);
        $this->assertSame(['search', 'book_id', 'library_member_id', 'status', 'from', 'to'], LibraryReportController::FILTERS['circulation']);
        $this->assertSame(['search', 'book_id', 'library_member_id', 'status', 'from', 'to'], LibraryReportController::FILTERS['overdue']);
        $this->assertSame(['search', 'library_member_id', 'book_id', 'status', 'from', 'to'], LibraryReportController::FILTERS['fines']);
        $this->assertSame(['search', 'book_id', 'book_copy_id', 'library_member_id', 'status', 'from', 'to'], LibraryReportController::FILTERS['lost_damaged']);
        $this->assertSame([], LibraryReportController::FILTERS['summary']);

        // The Summary has no filters at all — not even a date window.
        $this->assertNotContains('from', LibraryReportController::FILTERS['summary']);
        $this->assertNotContains('status', LibraryReportController::FILTERS['summary']);
    }

    /* ------------------------------------------------------------------ *\
     * Sidebar placement
     * \* ------------------------------------------------------------------ */

    public function test_reports_menu_lists_library_reports_after_hr_and_outside_the_library_group(): void
    {
        $college = $this->makeCollege('LIBRMENU');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_dashboard.view', 'student_reports.view', 'academic_reports.view',
            'examination_reports.view', 'finance_reports.view', 'hr_reports.view', 'library_reports.view',
            'books.view', 'library_dashboard.view',
        ]);
        $html = $this->asCollege($college, $user)->get(route('library-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory / Asset Management<');
        $reports = strpos($html, '>REPORTS<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $examination = strpos($html, 'href="'.route('examination-reports.index').'"');
        $finance = strpos($html, 'href="'.route('finance-reports.index').'"');
        $hr = strpos($html, 'href="'.route('hr-reports.index').'"');
        $library = strpos($html, 'href="'.route('library-reports.index').'"');
        $platform = strpos($html, '>ADMINISTRATION / SETTINGS<', (int) $reports);
        $platform = $platform === false ? strpos($html, '</nav>', (int) $reports) : $platform;
        $this->assertNotFalse($inventory);
        $this->assertTrue(
            $inventory < $reports && $reports < $student && $student < $academic
            && $academic < $examination && $examination < $finance && $finance < $hr && $hr < $library && $library < $platform
        );
        $this->assertSame(1, substr_count($html, '>REPORTS<'));

        // Six report links live between REPORTS and Administration / Settings; the Library
        // Reports child is the last one and carries a library / books icon.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertSame(6, substr_count($menu, 'class="nav-link"'));
        $this->assertStringContainsString('📚', $menu);
        $this->assertStringContainsString('🧑‍💼', $menu, 'HR Reports must stay in the section, before Library Reports.');

        // The REPORTS heading itself stays plain (no link, no reordering).
        $this->assertStringNotContainsString('<a', substr($html, $reports - 80, 80));

        // Library Reports left the Library Management group: the group keeps its
        // nine operational entries and no longer mentions the reports.
        $libraryStart = strpos($html, '>Library Management</div>');
        $this->assertNotFalse($libraryStart);
        $libraryEnd = strpos($html, 'uppercase tracking-widest', $libraryStart + 1);
        $group = substr($html, $libraryStart, $libraryEnd === false ? null : $libraryEnd - $libraryStart);
        $this->assertSame(2, substr_count($group, 'class="nav-link"'), 'Only the two permitted operational entries may render.');
        $this->assertStringContainsString('Library Dashboard', $group);
        $this->assertStringContainsString('Books', $group);
        $this->assertStringNotContainsString('Library Reports', $group);
        $this->assertStringNotContainsString(route('library-reports.index'), $group);

        // A report permission is the only thing that reveals the section: a user
        // holding only library_reports.view sees REPORTS and the Library link,
        // but no Library Management group at all.
        $solo = $this->makeUserWithPermissions($college, self::VIEW);
        $soloHtml = $this->asCollege($college, $solo)->get(route('library-reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>REPORTS<', $soloHtml);
        $this->assertStringContainsString('href="'.route('library-reports.index').'"', $soloHtml);
        $this->assertStringNotContainsString('>Library Management</div>', $soloHtml);
    }

    /* ------------------------------------------------------------------ *\
     * Tenant isolation + forged foreign filter ids
     * \* ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('LIBRTA');
        $b = $this->makeCollege('LIBRTB');

        $bCategory = $this->makeBookCategory($b, ['name' => 'B Category']);
        $bAuthor = $this->makeAuthor($b, ['name' => 'B Author']);
        $bPublisher = $this->makePublisher($b, ['name' => 'B Publisher']);
        $bBook = $this->makeBook($b, ['book_category_id' => $bCategory->id, 'publisher_id' => $bPublisher->id, 'title' => 'B Book'], [$bAuthor]);
        $bCopy = $this->makeBookCopy($b, $bBook, ['status' => BookCopy::STATUS_LOST]);
        $bMember = $this->makeLibraryMember($b);
        $bTransaction = $this->makeIssuedTransaction($b, $this->makeBookCopy($b, $bBook, ['copy_number' => 2]), $bMember, [
            'issued_on' => now()->subDays(40)->toDateString(),
            'due_on' => now()->subDays(30)->toDateString(),
        ]);
        LibraryFine::create([
            'college_id' => $b->id,
            'library_transaction_id' => $bTransaction->id,
            'library_member_id' => $bMember->id,
            'type' => LibraryFine::TYPE_OVERDUE,
            'period_start' => now()->subDays(29)->toDateString(),
            'period_end' => now()->toDateString(),
            'days_overdue' => 29,
            'rate_per_day' => 5,
            'assessed_amount' => 145,
            'paid_amount' => 0,
            'status' => LibraryFine::STATUS_ASSESSED,
        ]);

        $bReporter = $this->makeUserWithPermissions($b, self::VIEW);
        $this->asCollege($b, $bReporter)->get(route('library-reports.index', ['report' => 'books']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        // The same screens in college A must never see college B, even when the
        // request forges every foreign id it can: the rows simply match nothing.
        $aReporter = $this->reporter($a);
        foreach (array_keys(LibraryReportController::REPORTS) as $report) {
            $page = $this->get(route('library-reports.index', [
                'report' => $report,
                'book_id' => $bBook->id,
                'book_category_id' => $bCategory->id,
                'author_id' => $bAuthor->id,
                'publisher_id' => $bPublisher->id,
                'book_copy_id' => $bCopy->id,
                'library_member_id' => $bMember->id,
                'student_enrollment_id' => $bMember->student_enrollment_id,
            ]))->assertOk();

            if ($report === 'summary') {
                $page->assertViewHas('summary', fn (array $summary) => $summary['books']['total'] === 0
                    && $summary['copies']['total'] === 0 && $summary['members']['total'] === 0
                    && $summary['circulation']['issues'] === 0 && $summary['fines']['fines'] === 0
                    && $summary['lost_damaged']['copies'] === 0);
            } else {
                $page->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }

        // Without filters the foreign rows are still invisible.
        $this->get(route('library-reports.index', ['report' => 'fines']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *\
     * Books report
     * \* ------------------------------------------------------------------ */

    public function test_books_report_reads_category_author_publisher_relationships_and_copy_counts(): void
    {
        $college = $this->makeCollege('LIBRBOOK');
        $this->reporter($college);

        $category = $this->makeBookCategory($college, ['name' => 'Computer Science']);
        $otherCategory = $this->makeBookCategory($college, ['name' => 'Fiction']);
        $author = $this->makeAuthor($college, ['name' => 'Ada Lovelace']);
        $otherAuthor = $this->makeAuthor($college, ['name' => 'Grace Hopper']);
        $publisher = $this->makePublisher($college, ['name' => 'Analytical Press']);
        $book = $this->makeBook($college, [
            'book_category_id' => $category->id, 'publisher_id' => $publisher->id,
            'title' => 'Algorithms', 'code' => 'BK-ALG', 'isbn' => '978-0-262-03384-8', 'language' => 'English',
        ], [$author]);
        $other = $this->makeBook($college, [
            'book_category_id' => $otherCategory->id, 'title' => 'Other Title', 'code' => 'BK-OTH',
            'status' => Book::STATUS_INACTIVE,
        ], [$otherAuthor]);
        $this->makeBookCopy($college, $book, ['copy_number' => 1, 'status' => BookCopy::STATUS_AVAILABLE]);
        $this->makeBookCopy($college, $book, ['copy_number' => 2, 'status' => BookCopy::STATUS_AVAILABLE]);
        $this->makeBookCopy($college, $book, ['copy_number' => 3, 'status' => BookCopy::STATUS_ISSUED]);

        $page = $this->get(route('library-reports.index', ['report' => 'books']))->assertOk();
        $page->assertSee('Algorithms')->assertSee('Computer Science')->assertSee('Ada Lovelace')->assertSee('Analytical Press');
        $page->assertViewHas('totals', fn (array $totals) => $totals['books'] === 2 && $totals['active'] === 1
            && $totals['inactive'] === 1 && $totals['copies'] === 3);
        $page->assertViewHas('rows', function ($rows) use ($book) {
            $row = $rows->firstWhere('id', $book->id);

            return $row !== null && (int) $row->copies_count === 3
                && (int) $row->available_copies_count === 2 && (int) $row->issued_copies_count === 1;
        });

        // Filters narrow on the relationship, the vocabulary and the free text.
        foreach ([
            ['book_category_id' => $category->id],
            ['author_id' => $author->id],
            ['publisher_id' => $publisher->id],
            ['search' => 'Alg'],
            ['search' => '978-0-262'],
        ] as $filter) {
            $this->get(route('library-reports.index', ['report' => 'books', ...$filter]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $book->id);
        }
        $this->get(route('library-reports.index', ['report' => 'books', 'status' => Book::STATUS_INACTIVE]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $other->id);
        // The relationship filters follow the credits: the other title is
        // reached through its own author, and nothing matches a made-up term.
        $this->get(route('library-reports.index', ['report' => 'books', 'author_id' => $otherAuthor->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $other->id);
        $this->get(route('library-reports.index', ['report' => 'books', 'search' => 'nothing-here']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('library-reports.index', ['report' => 'books', 'status' => Book::STATUS_ACTIVE]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $book->id);
    }

    /* ------------------------------------------------------------------ *\
     * Book copies report
     * \* ------------------------------------------------------------------ */

    public function test_book_copies_report_filters_by_book_status_and_derived_availability(): void
    {
        $college = $this->makeCollege('LIBRCOPY');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Shelved Title', 'code' => 'BK-SHELF']);
        $other = $this->makeBook($college, ['title' => 'Another Title']);
        $available = $this->makeBookCopy($college, $book, ['copy_number' => 1, 'accession_number' => 'ACC-A1', 'barcode' => 'BAR-A1', 'location' => 'Rack 1']);
        $issued = $this->makeBookCopy($college, $book, ['copy_number' => 2, 'accession_number' => 'ACC-A2', 'status' => BookCopy::STATUS_ISSUED]);
        $withdrawn = $this->makeBookCopy($college, $book, ['copy_number' => 3, 'accession_number' => 'ACC-A3', 'status' => BookCopy::STATUS_WITHDRAWN]);
        $foreign = $this->makeBookCopy($college, $other, ['accession_number' => 'ACC-B1']);

        $page = $this->get(route('library-reports.index', ['report' => 'copies']))->assertOk();
        $page->assertSee('ACC-A1')->assertSee('Shelved Title')->assertSee('Rack 1');
        $page->assertViewHas('totals', fn (array $totals) => $totals['copies'] === 4 && $totals['available'] === 2
            && $totals['on_loan'] === 1 && $totals['withdrawn'] === 1);
        $page->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->contains($foreign->id));

        $this->get(route('library-reports.index', ['report' => 'copies', 'book_id' => $book->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->doesntContain($foreign->id));
        $this->get(route('library-reports.index', ['report' => 'copies', 'status' => BookCopy::STATUS_ISSUED]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $issued->id);
        $this->get(route('library-reports.index', ['report' => 'copies', 'availability' => 'available']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->contains($available->id));
        $this->get(route('library-reports.index', ['report' => 'copies', 'availability' => 'on_loan']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $issued->id);
        $this->get(route('library-reports.index', ['report' => 'copies', 'availability' => 'unavailable']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $withdrawn->id);
        $this->get(route('library-reports.index', ['report' => 'copies', 'search' => 'ACC-A']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('library-reports.index', ['report' => 'copies', 'search' => 'Shelved']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
    }

    /* ------------------------------------------------------------------ *\
     * Library members report
     * \* ------------------------------------------------------------------ */

    public function test_library_members_report_reads_member_relationships_and_loan_counts(): void
    {
        $college = $this->makeCollege('LIBRMEM');
        $this->reporter($college);

        [$student, $enrollment] = $this->makeLibraryEnrollment($college, ['first_name' => 'Asha', 'last_name' => 'Nair']);
        $member = $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-1001']);
        $otherMember = $this->makeLibraryMember($college, $this->makeLibraryEnrollment($college, ['first_name' => 'Ravi', 'last_name' => 'Kumar'])[1], ['member_code' => 'LM-2002', 'status' => LibraryMember::STATUS_INACTIVE]);
        $open = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(10)->toDateString(),
        ]);
        $returned = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => now()->subDays(20)->toDateString(),
            'due_on' => now()->subDays(5)->toDateString(),
            'returned_on' => now()->subDays(4)->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);

        $page = $this->get(route('library-reports.index', ['report' => 'members']))->assertOk();
        $page->assertSee('LM-1001')->assertSee('Asha Nair')->assertSee($enrollment->enrollment_number);
        $page->assertViewHas('totals', fn (array $totals) => $totals['members'] === 2 && $totals['active'] === 1
            && $totals['inactive'] === 1 && $totals['with_open_issues'] === 1 && $totals['open_issues'] === 1);
        $page->assertViewHas('rows', function ($rows) use ($member) {
            $row = $rows->firstWhere('id', $member->id);

            return $row !== null && (int) $row->transactions_count === 2
                && (int) $row->open_issues_count === 1 && (int) $row->overdue_issues_count === 1;
        });

        $this->get(route('library-reports.index', ['report' => 'members', 'status' => LibraryMember::STATUS_INACTIVE]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $otherMember->id);
        $this->get(route('library-reports.index', ['report' => 'members', 'student_enrollment_id' => $enrollment->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $member->id);
        $this->get(route('library-reports.index', ['report' => 'members', 'search' => 'Asha']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $member->id);
        $this->get(route('library-reports.index', ['report' => 'members', 'search' => $enrollment->enrollment_number]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $member->id);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame(LibraryTransaction::STATUS_RETURNED, $returned->status);
        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $open->status);
    }

    /* ------------------------------------------------------------------ *\
     * Issue / return report
     * \* ------------------------------------------------------------------ */

    public function test_issue_return_report_reads_circulation_renewals_and_the_date_window(): void
    {
        $college = $this->makeCollege('LIBRCIRC');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Circulating Title', 'code' => 'BK-CIRC']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-C1']);
        $member = $this->makeLibraryMember($college);
        $open = $this->makeIssuedTransaction($college, $copy, $member, [
            'issued_on' => '2026-09-01',
            'due_on' => '2026-09-15',
        ]);
        LibraryRenewal::create([
            'college_id' => $college->id,
            'issue_transaction_id' => $open->id,
            'old_due_date' => '2026-09-15',
            'new_due_date' => '2026-09-29',
            'renewed_on' => '2026-09-14',
        ]);
        $returnedLate = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => '2026-08-01',
            'due_on' => '2026-08-10',
            'returned_on' => '2026-08-20',
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);
        $lost = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => '2026-07-01',
            'due_on' => '2026-07-15',
            'status' => LibraryTransaction::STATUS_LOST,
        ]);

        $page = $this->get(route('library-reports.index', ['report' => 'circulation']))->assertOk();
        $page->assertSee('Circulating Title')->assertSee('ACC-C1');
        $page->assertViewHas('totals', fn (array $totals) => $totals['issues'] === 3 && $totals['issued'] === 1
            && $totals['returned'] === 1 && $totals['lost'] === 1 && $totals['returned_late'] === 1 && $totals['renewals'] === 1);
        $page->assertViewHas('rows', function ($rows) use ($open, $returnedLate, $lost) {
            return $rows->pluck('id')->contains($open->id)
                && $rows->pluck('id')->contains($returnedLate->id)
                && $rows->pluck('id')->contains($lost->id)
                && (int) $rows->firstWhere('id', $open->id)->renewals_count === 1;
        });

        $this->get(route('library-reports.index', ['report' => 'circulation', 'status' => LibraryTransaction::STATUS_RETURNED]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $returnedLate->id);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'book_id' => $book->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $open->id);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'library_member_id' => $member->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $returnedLate->id);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'search' => 'ACC-C1']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $open->id);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'search' => $member->member_code]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'search' => 'Circulating']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('library-reports.index', ['report' => 'circulation', 'search' => 'no-such-issue']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *\
     * Overdue report
     * \* ------------------------------------------------------------------ */

    public function test_overdue_report_uses_the_existing_overdue_definition(): void
    {
        $college = $this->makeCollege('LIBROVER');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Overdue Title', 'code' => 'BK-OVER']);
        $member = $this->makeLibraryMember($college);
        $stillOverdue = $this->makeIssuedTransaction($college, $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-OD1']), $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(10)->toDateString(),
        ]);
        $returnedLate = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => now()->subDays(40)->toDateString(),
            'due_on' => now()->subDays(25)->toDateString(),
            'returned_on' => now()->subDays(20)->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);
        $onTime = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => now()->subDays(3)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
        ]);
        $returnedOnTime = $this->makeIssuedTransaction($college, null, $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->addDays(2)->toDateString(),
            'returned_on' => now()->subDay()->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);

        $page = $this->get(route('library-reports.index', ['report' => 'overdue']))->assertOk();
        $page->assertSee('Overdue Title')->assertSee('ACC-OD1');
        $page->assertViewHas('totals', fn (array $totals) => $totals['overdue'] === 2 && $totals['still_issued'] === 1 && $totals['returned_late'] === 1);
        $page->assertViewHas('rows', fn ($rows) => $rows->total() === 2
            && $rows->pluck('id')->contains($stillOverdue->id)
            && $rows->pluck('id')->contains($returnedLate->id)
            && ! $rows->pluck('id')->contains($onTime->id)
            && ! $rows->pluck('id')->contains($returnedOnTime->id));

        // The overdue report also reads the same isOverdue() rule the model uses.
        $this->assertTrue($this->withTenant($college, fn () => LibraryTransaction::find($stillOverdue->id)->isOverdue()));
        $this->assertFalse($this->withTenant($college, fn () => LibraryTransaction::find($onTime->id)->isOverdue()));

        $this->get(route('library-reports.index', ['report' => 'overdue', 'status' => LibraryTransaction::STATUS_RETURNED]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $returnedLate->id);
        $this->get(route('library-reports.index', ['report' => 'overdue', 'book_id' => $book->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $stillOverdue->id);
        $this->get(route('library-reports.index', ['report' => 'overdue', 'library_member_id' => $member->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('library-reports.index', [
            'report' => 'overdue',
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->subDays(20)->toDateString(),
        ]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $returnedLate->id);
        $this->get(route('library-reports.index', ['report' => 'overdue', 'search' => 'Overdue']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    /* ------------------------------------------------------------------ *\
     * Fine report — the stored amounts, never a recalculation
     * \* ------------------------------------------------------------------ */

    public function test_fine_report_reuses_the_stored_fine_amounts_and_never_recalculates(): void
    {
        $college = $this->makeCollege('LIBRFINE');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Fined Title', 'code' => 'BK-FINE']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-FINE']);
        $transaction = $this->makeIssuedTransaction($college, $this->makeBookCopy($college, $book), $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(10)->toDateString(),
            'returned_on' => now()->subDays(6)->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);

        // Amounts are the ones a fine row stores — deliberately different from
        // anything a report could recompute from the dates.
        $assessed = LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => LibraryFine::TYPE_OVERDUE,
            'period_start' => now()->subDays(9)->toDateString(),
            'period_end' => now()->subDays(6)->toDateString(),
            'days_overdue' => 4,
            'rate_per_day' => 2.50,
            'assessed_amount' => 10.00,
            'paid_amount' => 0,
            'status' => LibraryFine::STATUS_ASSESSED,
        ]);
        $paid = LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => LibraryFine::TYPE_LOST_DAMAGED,
            'period_start' => now()->subDays(5)->toDateString(),
            'period_end' => now()->subDays(5)->toDateString(),
            'days_overdue' => 1,
            'rate_per_day' => 100,
            'assessed_amount' => 100.00,
            'paid_amount' => 40.00,
            'status' => LibraryFine::STATUS_PAID,
            'payment_reference' => 'RCPT-1',
        ]);

        $page = $this->get(route('library-reports.index', ['report' => 'fines']))->assertOk();
        $page->assertSee('Fined Title')->assertSee('LM-FINE')->assertSee('RCPT-1');
        $page->assertViewHas('totals', fn (array $totals) => $totals['fines'] === 2 && $totals['assessed'] === 1
            && $totals['paid'] === 1 && abs($totals['assessed_amount'] - 110.0) < 0.001
            && abs($totals['paid_amount'] - 40.0) < 0.001 && abs($totals['outstanding'] - 70.0) < 0.001);
        $page->assertViewHas('rows', fn ($rows) => abs((float) $rows->firstWhere('id', $paid->id)->outstanding() - 60.0) < 0.001);

        // LibraryFine::outstanding() stays the single source of the outstanding figure.
        $this->assertSame('60.00', $this->withTenant($college, fn () => LibraryFine::find($paid->id)->outstanding()));

        $this->get(route('library-reports.index', ['report' => 'fines', 'status' => LibraryFine::STATUS_PAID]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $paid->id);
        $this->get(route('library-reports.index', ['report' => 'fines', 'library_member_id' => $member->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('library-reports.index', ['report' => 'fines', 'book_id' => $book->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('library-reports.index', ['report' => 'fines', 'search' => 'RCPT']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $paid->id);
        $this->get(route('library-reports.index', ['report' => 'fines', 'search' => 'Fined']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('library-reports.index', [
            'report' => 'fines',
            'from' => now()->subDays(9)->toDateString(),
            'to' => now()->subDays(6)->toDateString(),
        ]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $assessed->id);
        $this->assertSame('10.00', $this->withTenant($college, fn () => LibraryFine::find($assessed->id)->outstanding()));
    }

    /* ------------------------------------------------------------------ *\
     * Lost / damaged report
     * \* ------------------------------------------------------------------ */

    public function test_lost_damaged_report_lists_only_lost_and_damaged_copies(): void
    {
        $college = $this->makeCollege('LIBRLOST');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Missing Title', 'code' => 'BK-LOST']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-LOST']);
        $lost = $this->makeBookCopy($college, $book, ['copy_number' => 1, 'accession_number' => 'ACC-L1', 'status' => BookCopy::STATUS_LOST]);
        $damaged = $this->makeBookCopy($college, $book, ['copy_number' => 2, 'accession_number' => 'ACC-L2', 'status' => BookCopy::STATUS_DAMAGED]);
        $available = $this->makeBookCopy($college, $book, ['copy_number' => 3, 'accession_number' => 'ACC-L3']);
        $this->asCollege($college, $this->reporter($college));
        $this->withTenant($college, function () use ($college, $lost, $member) {
            LibraryTransaction::create([
                'college_id' => $college->id,
                'book_copy_id' => $lost->id,
                'library_member_id' => $member->id,
                'issued_on' => now()->subDays(40)->toDateString(),
                'due_on' => now()->subDays(25)->toDateString(),
                'status' => LibraryTransaction::STATUS_ISSUED,
            ]);
        });

        $page = $this->get(route('library-reports.index', ['report' => 'lost_damaged']))->assertOk();
        $page->assertSee('Missing Title')->assertSee('ACC-L1')->assertSee('ACC-L2')->assertSee('LM-LOST');
        $page->assertDontSee('ACC-L3');
        $page->assertViewHas('totals', fn (array $totals) => $totals['copies'] === 2 && $totals['lost'] === 1
            && $totals['damaged'] === 1 && $totals['titles'] === 1);

        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'status' => BookCopy::STATUS_LOST]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $lost->id);
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'book_id' => $book->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'book_copy_id' => $damaged->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $damaged->id);
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'library_member_id' => $member->id]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $lost->id);
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'search' => 'ACC-L2']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $damaged->id);
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'search' => 'Missing']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);

        // A status the report cannot list is rejected, and an available copy is
        // never part of this report.
        $this->get(route('library-reports.index', ['report' => 'lost_damaged', 'status' => BookCopy::STATUS_AVAILABLE]))
            ->assertSessionHasErrors('status');
        $this->assertSame(BookCopy::STATUS_AVAILABLE, $available->fresh()->status);
    }

    /* ------------------------------------------------------------------ *\
     * Library summary
     * \* ------------------------------------------------------------------ */

    public function test_library_summary_aggregates_the_live_library_records(): void
    {
        $college = $this->makeCollege('LIBRSUM');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Summary Title']);
        $this->makeBook($college, ['book_category_id' => $book->book_category_id, 'status' => Book::STATUS_INACTIVE]);
        $this->makeBookCopy($college, $book, ['copy_number' => 1, 'status' => BookCopy::STATUS_AVAILABLE]);
        $issuedCopy = $this->makeBookCopy($college, $book, ['copy_number' => 2, 'status' => BookCopy::STATUS_ISSUED]);
        $this->makeBookCopy($college, $book, ['copy_number' => 3, 'status' => BookCopy::STATUS_LOST]);
        $this->makeBookCopy($college, $book, ['copy_number' => 4, 'status' => BookCopy::STATUS_DAMAGED]);
        $member = $this->makeLibraryMember($college, null, ['status' => LibraryMember::STATUS_ACTIVE]);
        $this->makeLibraryMember($college, null, ['status' => LibraryMember::STATUS_SUSPENDED]);
        $transaction = $this->makeIssuedTransaction($college, $issuedCopy, $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(10)->toDateString(),
        ]);
        LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => LibraryFine::TYPE_OVERDUE,
            'period_start' => now()->subDays(9)->toDateString(),
            'period_end' => now()->toDateString(),
            'days_overdue' => 9,
            'rate_per_day' => 2,
            'assessed_amount' => 18.00,
            'paid_amount' => 8.00,
            'status' => LibraryFine::STATUS_ASSESSED,
        ]);

        $page = $this->get(route('library-reports.index', ['report' => 'summary']))->assertOk();
        $page->assertViewHas('summary', function (array $summary) {
            return $summary['books']['total'] === 2 && $summary['books']['active'] === 1 && $summary['books']['inactive'] === 1
                && $summary['copies']['total'] === 4 && $summary['copies']['available'] === 1
                && $summary['copies']['issued'] === 1 && $summary['copies']['lost'] === 1 && $summary['copies']['damaged'] === 1
                && $summary['members']['total'] === 2 && $summary['members']['active'] === 1 && $summary['members']['suspended'] === 1
                && $summary['circulation']['issues'] === 1 && $summary['circulation']['issued'] === 1 && $summary['circulation']['overdue'] === 1
                && $summary['fines']['fines'] === 1 && abs($summary['fines']['assessed_amount'] - 18.0) < 0.001
                && abs($summary['fines']['paid_amount'] - 8.0) < 0.001 && abs($summary['fines']['outstanding'] - 10.0) < 0.001
                && $summary['lost_damaged']['copies'] === 2 && $summary['lost_damaged']['titles'] === 1
                && $summary['master']['categories'] === 1;
        });
        // The Summary offers no filters at all: no input carries a filter name.
        $page->assertDontSee('name="book_id"', false)->assertDontSee('name="from"', false);

        // The Summary ignores filters entirely (it has none) instead of failing.
        $this->get(route('library-reports.index', ['report' => 'summary', 'book_id' => 999999, 'from' => 'not-a-date']))
            ->assertOk()->assertViewHas('summary', fn (array $summary) => $summary['books']['total'] === 2);
    }

    /* ------------------------------------------------------------------ *\
     * Empty states + invalid filters
     * \* ------------------------------------------------------------------ */

    public function test_empty_results_invalid_filters_and_irrelevant_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('LIBREMPTY');
        $this->reporter($college);

        foreach (LibraryReportController::REPORTS as $report => $label) {
            $page = $this->get(route('library-reports.index', ['report' => $report]))
                ->assertOk()->assertSee('No ');

            if ($report === 'summary') {
                $page->assertViewHas('summary', fn (array $s) => $s['books']['total'] === 0
                    && $s['copies']['total'] === 0 && $s['members']['total'] === 0
                    && $s['circulation']['issues'] === 0 && $s['fines']['fines'] === 0
                    && $s['lost_damaged']['copies'] === 0 && $s['master']['authors'] === 0);
            } else {
                $page->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }

        // An unknown report falls back to the first report, never to an error.
        $this->get(route('library-reports.index', ['report' => 'not_a_report']))
            ->assertOk()->assertViewHas('report', 'books');

        // Vocabularies and date windows are validated per report.
        $this->get(route('library-reports.index', ['report' => 'books', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'copies', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'copies', 'availability' => 'made-up']))->assertSessionHasErrors('availability');
        $this->get(route('library-reports.index', ['report' => 'members', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'circulation', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'overdue', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'fines', 'status' => 'made-up']))->assertSessionHasErrors('status');
        $this->get(route('library-reports.index', ['report' => 'circulation', 'from' => '2026-09-01', 'to' => '2026-08-01']))->assertSessionHasErrors('to');
        $this->get(route('library-reports.index', ['report' => 'fines', 'from' => '2026-13-01']))->assertSessionHasErrors('from');
        $this->get(route('library-reports.index', ['report' => 'books', 'book_category_id' => 'abc']))->assertSessionHasErrors('book_category_id');
        $this->get(route('library-reports.index', ['report' => 'copies', 'book_id' => 'abc']))->assertSessionHasErrors('book_id');

        // Filter keys a report does not use are ignored, not enforced.
        $this->get(route('library-reports.index', [
            'report' => 'summary', 'book_id' => 999999, 'book_category_id' => 999999, 'author_id' => 999999,
            'publisher_id' => 999999, 'book_copy_id' => 999999, 'library_member_id' => 999999,
            'student_enrollment_id' => 999999, 'status' => 'made-up', 'availability' => 'made-up', 'from' => 'not-a-date',
        ]))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['book_id'] === null
                && $filters['book_category_id'] === null && $filters['author_id'] === null
                && $filters['publisher_id'] === null && $filters['book_copy_id'] === null
                && $filters['library_member_id'] === null && $filters['student_enrollment_id'] === null
                && $filters['status'] === null && $filters['availability'] === null && $filters['from'] === null);

        // Ids that point nowhere simply match nothing.
        $this->get(route('library-reports.index', ['report' => 'books', 'book_category_id' => 999999]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('library-reports.index', ['report' => 'fines', 'library_member_id' => 999999]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *\
     * Soft deletes + inactive records
     * \* ------------------------------------------------------------------ */

    public function test_soft_deleted_masters_disappear_while_history_never_does(): void
    {
        $college = $this->makeCollege('LIBRDEL');
        $this->reporter($college);

        $book = $this->makeBook($college, ['title' => 'Deleted Title', 'code' => 'BK-DEL']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-DEL']);
        $member = $this->makeLibraryMember($college, null, ['member_code' => 'LM-DEL']);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(15)->toDateString(),
            'returned_on' => now()->subDays(10)->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);
        LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => LibraryFine::TYPE_OVERDUE,
            'period_start' => now()->subDays(14)->toDateString(),
            'period_end' => now()->subDays(10)->toDateString(),
            'days_overdue' => 5,
            'rate_per_day' => 1,
            'assessed_amount' => 5.00,
            'status' => LibraryFine::STATUS_ASSESSED,
        ]);

        $this->withTenant($college, function () use ($book, $copy, $member) {
            $book->delete();
            $copy->delete();
            $member->delete();
        });

        // Soft-deleted masters leave the catalogue reports…
        $this->get(route('library-reports.index', ['report' => 'books']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('library-reports.index', ['report' => 'copies']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('library-reports.index', ['report' => 'members']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);

        // …while the circulation history and its fine stay visible exactly as
        // the operational screens show them (they carry no soft deletes).
        $this->get(route('library-reports.index', ['report' => 'circulation']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('library-reports.index', ['report' => 'fines']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    /* ------------------------------------------------------------------ *\
     * Pagination
     * \* ------------------------------------------------------------------ */

    public function test_lists_paginate_deterministically_and_keep_their_filters(): void
    {
        $college = $this->makeCollege('LIBRPAGE');
        $this->reporter($college);
        foreach (range(1, 25) as $i) {
            $this->makeBook($college, ['title' => sprintf('Paged Title %02d', $i), 'code' => sprintf('BK-PG-%02d', $i)]);
        }
        foreach (range(1, 3) as $i) {
            $this->makeBook($college, ['title' => sprintf('Old Title %02d', $i), 'status' => Book::STATUS_INACTIVE]);
        }

        $first = $this->get(route('library-reports.index', ['report' => 'books', 'status' => Book::STATUS_ACTIVE]))
            ->assertOk()
            ->assertSee('Showing 1–20 of 25')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20 && $rows->count() === 20);
        $paginator = $first->viewData('rows');
        $this->assertStringContainsString('report=books', $paginator->nextPageUrl());
        $this->assertStringContainsString('status=active', $paginator->nextPageUrl());

        $second = $this->get(route('library-reports.index', ['report' => 'books', 'status' => Book::STATUS_ACTIVE, 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5);
        $pageOneIds = $first->viewData('rows')->pluck('id')->all();
        $pageTwoIds = $second->viewData('rows')->pluck('id')->all();
        $this->assertSame([], array_intersect($pageOneIds, $pageTwoIds), 'Pages must never repeat rows.');
        $this->assertCount(25, array_unique([...$pageOneIds, ...$pageTwoIds]));

        // The fines report paginates with the same rules and keeps a date window.
        $book = Book::query()->firstOrFail();
        $copy = $this->makeBookCopy($college, $book);
        $member = $this->makeLibraryMember($college);
        foreach (range(1, 25) as $day) {
            $transaction = LibraryTransaction::create([
                'college_id' => $college->id,
                'book_copy_id' => $copy->id,
                'library_member_id' => $member->id,
                'issued_on' => '2026-07-01',
                'due_on' => '2026-07-02',
                'returned_on' => sprintf('2026-08-%02d', $day),
                'status' => LibraryTransaction::STATUS_RETURNED,
            ]);
            LibraryFine::create([
                'college_id' => $college->id,
                'library_transaction_id' => $transaction->id,
                'library_member_id' => $member->id,
                'type' => LibraryFine::TYPE_OVERDUE,
                'period_start' => '2026-07-03',
                'period_end' => sprintf('2026-08-%02d', $day),
                'days_overdue' => $day,
                'rate_per_day' => 1,
                'assessed_amount' => $day,
                'status' => LibraryFine::STATUS_ASSESSED,
            ]);
        }
        $page = $this->get(route('library-reports.index', ['report' => 'fines', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20 && $rows->count() === 20);
        $this->assertStringContainsString('from=2026-08-01', $page->viewData('rows')->nextPageUrl());
        $this->get(route('library-reports.index', ['report' => 'fines', 'from' => '2026-08-01', 'to' => '2026-08-31', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5
                && $rows->pluck('id')->doesntContain($page->viewData('rows')->first()->id));
    }

    /* ------------------------------------------------------------------ *\
     * GET-only + read-only
     * \* ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('LIBRREAD');
        $this->reporter($college);
        $this->world($college, 'READ');

        $before = $this->snapshot();
        foreach (array_keys(LibraryReportController::REPORTS) as $report) {
            $this->get(route('library-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot(), 'Rendering every report must not change a single row.');

        $this->post(route('library-reports.index'), ['report' => 'summary'])->assertStatus(405);
        $this->put(route('library-reports.index'))->assertStatus(405);
        $this->patch(route('library-reports.index'))->assertStatus(405);
        $this->delete(route('library-reports.index'))->assertStatus(405);
        $this->get('/library-reports/create')->assertNotFound();
        $this->get('/library-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'library-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    public function test_legacy_library_reports_route_contract_is_preserved(): void
    {
        $college = $this->makeCollege('LIBRLEGACY');
        $this->reporter($college);
        $book = $this->makeBook($college, ['title' => 'Legacy Title', 'code' => 'BK-LEG']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-LEG', 'status' => BookCopy::STATUS_DAMAGED]);

        $html = $this->get(route('library-reports.index', ['report' => 'lost_damaged']))->assertOk()->getContent();
        $this->assertStringContainsString('ACC-LEG', $html);
        $this->assertStringContainsString('>Damaged</td>', $html);
        $this->assertStringNotContainsString('>Available</td>', $html);
        $this->assertStringNotContainsString('Create', $html);
    }

    /* ------------------------------------------------------------------ *\
     * Query-count / N+1 protection
     * \* ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('LIBRNPLUS');
        $this->reporter($college);
        $this->world($college, 'SMALL');

        $logs = fn (): array => collect(array_keys(LibraryReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queryLogFor(route('library-reports.index', ['report' => $report]))])
            ->all();
        $small = $logs();

        // Grow every dataset past one page (20 rows).
        foreach (range(1, 21) as $i) {
            $book = $this->makeBook($college, ['title' => 'Growth Book '.$i, 'code' => sprintf('BK-GROW-%03d', $i)]);
            $copy = $this->makeBookCopy($college, $book, ['status' => BookCopy::STATUS_AVAILABLE]);
            $member = $this->makeLibraryMember($college);
            $transaction = $this->makeIssuedTransaction($college, $copy, $member, [
                'issued_on' => now()->subDays(30)->toDateString(),
                'due_on' => now()->subDays(10)->toDateString(),
            ]);
            LibraryFine::create([
                'college_id' => $college->id,
                'library_transaction_id' => $transaction->id,
                'library_member_id' => $member->id,
                'type' => LibraryFine::TYPE_OVERDUE,
                'period_start' => now()->subDays(9)->toDateString(),
                'period_end' => now()->toDateString(),
                'days_overdue' => 9,
                'rate_per_day' => 1,
                'assessed_amount' => 9.00,
                'status' => LibraryFine::STATUS_ASSESSED,
            ]);
        }

        $grown = $logs();

        // Growing the tables may make Laravel SKIP an eager-load query, but it
        // must never ADD one: every report reads its page with a fixed number
        // of queries.
        foreach (array_keys(LibraryReportController::REPORTS) as $report) {
            $before = $small[$report];
            $after = $grown[$report];
            $this->assertLessThanOrEqual(
                count($before),
                count($after),
                sprintf(
                    "Report [%s] ran %d queries before growth and %d after; a page must not cost more queries as rows grow.\nNew queries after growth:\n%s",
                    $report,
                    count($before),
                    count($after),
                    implode("\n", array_slice(array_values(array_diff($after, $before)), 0, 12)),
                ),
            );
        }

        // One row and a full page of identically shaped collections cost the
        // same number of queries: nothing is loaded per row.
        foreach (['books', 'copies', 'members', 'circulation', 'overdue', 'fines', 'lost_damaged'] as $report) {
            $one = $this->queryLogFor(route('library-reports.index', ['report' => $report, 'search' => 'GROW-001']));
            $full = $this->queryLogFor(route('library-reports.index', ['report' => $report, 'search' => 'Growth']));
            $this->assertSame(
                count($one),
                count($full),
                sprintf('Report [%s] must not run a query per row (one row: %d queries, a full page: %d).', $report, count($one), count($full)),
            );
        }
    }

    /* ------------------------------------------------------------------ *\
     * Helpers
     * \* ------------------------------------------------------------------ */

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * A small but complete Library world: a catalogue with authors / publishers,
     * copies in every status, memberships, open + returned circulation, a
     * renewal and a stored fine.
     */
    private function world(College $college, string $prefix): void
    {
        $category = $this->makeBookCategory($college, ['name' => $prefix.' Category']);
        $author = $this->makeAuthor($college, ['name' => $prefix.' Author']);
        $publisher = $this->makePublisher($college, ['name' => $prefix.' Publisher']);

        $book = $this->makeBook($college, [
            'book_category_id' => $category->id,
            'publisher_id' => $publisher->id,
            'title' => $prefix.' Title',
            'code' => 'BK-'.$prefix,
        ], [$author]);
        $inactive = $this->makeBook($college, ['title' => $prefix.' Inactive', 'status' => Book::STATUS_INACTIVE]);

        $available = $this->makeBookCopy($college, $book, ['copy_number' => 1, 'status' => BookCopy::STATUS_AVAILABLE]);
        $issued = $this->makeBookCopy($college, $book, ['copy_number' => 2, 'status' => BookCopy::STATUS_LOST]);
        $damaged = $this->makeBookCopy($college, $inactive, ['status' => BookCopy::STATUS_DAMAGED]);

        $member = $this->makeLibraryMember($college);
        $suspended = $this->makeLibraryMember($college, null, ['status' => LibraryMember::STATUS_SUSPENDED]);

        $transaction = $this->makeIssuedTransaction($college, $issued, $member, [
            'issued_on' => now()->subDays(40)->toDateString(),
            'due_on' => now()->subDays(20)->toDateString(),
        ]);
        LibraryRenewal::create([
            'college_id' => $college->id,
            'issue_transaction_id' => $transaction->id,
            'old_due_date' => now()->subDays(20)->toDateString(),
            'new_due_date' => now()->subDays(10)->toDateString(),
            'renewed_on' => now()->subDays(25)->toDateString(),
        ]);
        $returned = $this->makeIssuedTransaction($college, $available, $suspended, [
            'issued_on' => now()->subDays(30)->toDateString(),
            'due_on' => now()->subDays(15)->toDateString(),
            'returned_on' => now()->subDays(10)->toDateString(),
            'status' => LibraryTransaction::STATUS_RETURNED,
        ]);
        LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $returned->id,
            'library_member_id' => $suspended->id,
            'type' => LibraryFine::TYPE_OVERDUE,
            'period_start' => now()->subDays(14)->toDateString(),
            'period_end' => now()->subDays(10)->toDateString(),
            'days_overdue' => 5,
            'rate_per_day' => 2,
            'assessed_amount' => 10.00,
            'paid_amount' => 4.00,
            'status' => LibraryFine::STATUS_ASSESSED,
        ]);
        $this->assertSame(BookCopy::STATUS_DAMAGED, $damaged->status);
    }

    private function queryLogFor(string $url): array
    {
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->get($url)->assertOk();

            return array_map(fn (array $entry) => (string) $entry['query'], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
