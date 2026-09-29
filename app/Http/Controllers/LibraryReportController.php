<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryReportService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Models\LibraryReport;
use App\Models\LibraryTransaction;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Library Reports — a READ-ONLY reporting layer over the existing Library
 * Management module (Books, Book Categories, Authors, Publishers, Book Copies,
 * Library Members, Issue / Return, Renewals and Fines).
 *
 * Nothing is persisted here: no report tables, no snapshots and no second copy
 * of any Library fact — every row and every figure is read live from the
 * operational models through LibraryReportService, always tenant-scoped by
 * CollegeScope, so a book / copy / member / category / author / publisher id
 * from another college can only produce an empty report.
 *
 * The module also never re-decides a Library rule: circulation statuses, member
 * eligibility, copy availability and fine amounts are the ones the operational
 * Library services stored (see LibraryReportService for the details).
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove a Library record from here.
 */
class LibraryReportController extends Controller
{
    /** The eight reports the screen can render, in navigation order. */
    public const REPORTS = [
        'books' => 'Books Report',
        'copies' => 'Book Copies Report',
        'members' => 'Library Members Report',
        'circulation' => 'Issue / Return Report',
        'overdue' => 'Overdue Books Report',
        'fines' => 'Fine Report',
        'lost_damaged' => 'Lost / Damaged Books Report',
        'summary' => 'Library Summary',
    ];

    /**
     * Filters that apply to each report; any other query parameter is ignored
     * and never enforced. Only the filters relevant to the selected report are
     * offered, so no report carries a filter it cannot use.
     */
    public const FILTERS = [
        // Catalogue report: `status` is the book status.
        'books' => ['search', 'book_category_id', 'author_id', 'publisher_id', 'status'],
        // Copies: `status` is the stored copy status, `availability` the derived bucket.
        'copies' => ['search', 'book_id', 'status', 'availability'],
        // Members: the Library module keeps memberships for student enrollments only.
        'members' => ['search', 'student_enrollment_id', 'status'],
        // Circulation: `status` is the transaction status, from/to the issue date.
        'circulation' => ['search', 'book_id', 'library_member_id', 'status', 'from', 'to'],
        // Overdue: `status` is the transaction status, from/to the due date.
        'overdue' => ['search', 'book_id', 'library_member_id', 'status', 'from', 'to'],
        // Fines: read from the stored fine rows, from/to the fine period.
        'fines' => ['search', 'library_member_id', 'book_id', 'status', 'from', 'to'],
        // Lost / damaged copies: `status` is the copy status (lost or damaged),
        // from/to the date the copy was acquired.
        'lost_damaged' => ['search', 'book_id', 'book_copy_id', 'library_member_id', 'status', 'from', 'to'],
        // The summary aggregates the whole library: it has no filters.
        'summary' => [],
    ];

    /** Date-range meaning per report (for the labels in the filter form). */
    public const DATE_LABELS = [
        'circulation' => 'Issue date',
        'overdue' => 'Due date',
        'fines' => 'Fine period',
        'lost_damaged' => 'Acquired date',
    ];

    private const KEYS = [
        'search', 'book_id', 'book_category_id', 'author_id', 'publisher_id', 'book_copy_id',
        'library_member_id', 'student_enrollment_id', 'status', 'availability', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'book_id', 'book_category_id', 'author_id', 'publisher_id', 'book_copy_id',
        'library_member_id', 'student_enrollment_id',
    ];

    private const STRING_KEYS = ['status', 'availability'];

    public function index(Request $request, LibraryReportService $reports): View
    {
        $this->authorize('viewAny', LibraryReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'books';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'copies' => $reports->copies($filters),
            'members' => $reports->members($filters),
            'circulation' => $reports->issues($filters),
            'overdue' => $reports->overdue($filters),
            'fines' => $reports->fines($filters),
            'lost_damaged' => $reports->lostDamaged($filters),
            'summary' => ['summary' => $reports->summary($filters)],
            default => $reports->books($filters),
        };

        return view('library_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusLabel' => self::statusLabel($report),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
            'searchLabel' => self::searchLabel($report),
            'searchPlaceholder' => self::searchPlaceholder($report),
            'availability' => LibraryReportService::AVAILABILITY,
        ]));
    }

    /**
     * The selectable statuses of the selected report's `status` filter
     * (empty = the report has no operational status filter).
     */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'books' => Book::STATUSES,
            'copies' => BookCopy::STATUSES,
            'members' => LibraryMember::STATUSES,
            'circulation', 'overdue' => LibraryTransaction::STATUSES,
            'fines' => [
                LibraryFine::STATUS_PENDING,
                LibraryFine::STATUS_ASSESSED,
                LibraryFine::STATUS_WAIVED,
                LibraryFine::STATUS_PAID,
            ],
            'lost_damaged' => LibraryReportService::LOST_DAMAGED_STATUSES,
            default => [],
        };
    }

    private static function statusLabel(string $report): string
    {
        return match ($report) {
            'books' => 'Book status',
            'copies' => 'Copy status',
            'members' => 'Member status',
            'circulation', 'overdue' => 'Issue status',
            'fines' => 'Fine status',
            'lost_damaged' => 'Copy status',
            default => 'Status',
        };
    }

    private static function searchLabel(string $report): string
    {
        return match ($report) {
            'books' => 'Search books',
            'copies', 'lost_damaged' => 'Search copies or books',
            'members' => 'Search members',
            default => 'Search issue or return',
        };
    }

    private static function searchPlaceholder(string $report): string
    {
        return match ($report) {
            'books' => 'Title, code, ISBN, edition or language',
            'copies', 'lost_damaged' => 'Accession number, barcode, location or book',
            'members' => 'Member code, enrollment number or student name',
            default => 'Book, accession number, member code or student name',
        };
    }

    /**
     * Validate and normalise the query string into the service's vocabulary.
     * Only the filters of the selected report take effect; the service always
     * receives the same keys.
     */
    private function filters(Request $request, string $report): array
    {
        $visible = self::FILTERS[$report];
        $uses = fn (string $key): bool => in_array($key, $visible, true);
        $statuses = self::statuses($report);

        $dateRules = $uses('from') ? ['date_format:Y-m-d'] : ['string', 'max:20'];

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'book_id' => ['nullable', 'integer', 'min:1'],
            'book_category_id' => ['nullable', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:1'],
            'publisher_id' => ['nullable', 'integer', 'min:1'],
            'book_copy_id' => ['nullable', 'integer', 'min:1'],
            'library_member_id' => ['nullable', 'integer', 'min:1'],
            'student_enrollment_id' => ['nullable', 'integer', 'min:1'],
            // A vocabulary is enforced only where the selected report actually
            // uses the filter; elsewhere the parameter is ignored, not judged.
            'status' => ['nullable', $statuses === [] ? 'string' : Rule::in($statuses)],
            'availability' => ['nullable', $uses('availability') ? Rule::in(LibraryReportService::AVAILABILITY) : 'string'],
            'from' => ['nullable', ...$dateRules],
            'to' => ['nullable', ...$dateRules, ...($uses('from') ? ['after_or_equal:from'] : [])],
        ]);

        $filters = array_fill_keys(self::KEYS, null);
        foreach ($visible as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }
        $filters['search'] = trim((string) ($filters['search'] ?? ''));

        foreach (self::INTEGER_KEYS as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (self::STRING_KEYS as $key) {
            $filters[$key] = filled($filters[$key]) ? trim((string) $filters[$key]) : null;
        }

        return $filters;
    }

    /** Tenant-scoped dropdown options, loaded only for the filters shown. */
    private function options(string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);
        $options = [];

        if (isset($visible['book_id'])) {
            $options['books'] = LibraryFormOptions::books();
        }
        if (isset($visible['book_category_id'])) {
            $options['categories'] = LibraryFormOptions::categories();
        }
        if (isset($visible['author_id'])) {
            $options['authors'] = LibraryFormOptions::authors();
        }
        if (isset($visible['publisher_id'])) {
            $options['publishers'] = LibraryFormOptions::publishers();
        }
        if (isset($visible['library_member_id'])) {
            $options['members'] = LibraryMember::query()
                ->with([
                    'studentEnrollment:id,student_id,enrollment_number',
                    'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                ])
                ->orderBy('member_code')->orderBy('id')
                ->get(['id', 'member_code', 'student_enrollment_id']);
        }
        if (isset($visible['student_enrollment_id'])) {
            $options['enrollments'] = StudentEnrollment::query()
                ->with(['student:id,first_name,middle_name,last_name,student_number', 'program:id,name,code'])
                ->orderBy('enrollment_number')
                ->orderBy('id')
                ->get(['id', 'student_id', 'program_id', 'enrollment_number']);
        }
        if (isset($visible['book_copy_id'])) {
            $options['copyOptions'] = BookCopy::query()
                ->whereIn('status', LibraryReportService::LOST_DAMAGED_STATUSES)
                ->with('book:id,title,code')
                ->orderBy('accession_number')
                ->orderBy('id')
                ->get(['id', 'book_id', 'accession_number', 'status']);
        }

        return $options;
    }
}
