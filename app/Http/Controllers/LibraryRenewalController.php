<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryRenewalService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\LibraryRenewal\StoreLibraryRenewalRequest;
use App\Models\LibraryRenewal;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renewals (Library Management).
 *
 * Append-only history of due-date extensions. There is no update or delete.
 */
class LibraryRenewalController extends Controller
{
    public function __construct(private readonly LibraryRenewalService $renewals)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LibraryRenewal::class);

        $query = LibraryRenewal::query()
            ->with([
                'issueTransaction.bookCopy.book:id,title,code',
                'issueTransaction.libraryMember.studentEnrollment.student',
                'renewer:id,name',
            ])
            ->orderByDesc('renewed_on')
            ->orderByDesc('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->whereHas('issueTransaction', function ($transaction) use ($needle): void {
                $transaction->whereHas('bookCopy', function ($copy) use ($needle): void {
                    $copy->where('accession_number', 'like', $needle)
                        ->orWhereHas('book', fn ($book) => $book->where('title', 'like', $needle));
                })->orWhereHas('libraryMember', function ($member) use ($needle): void {
                    $member->where('member_code', 'like', $needle);
                });
            });
        }

        if ($request->filled('issue_transaction_id')) {
            $query->where('issue_transaction_id', (int) $request->input('issue_transaction_id'));
        }

        return view('library_renewals.index', [
            'renewals' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'filters' => ['issue_transaction_id' => $request->input('issue_transaction_id')],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Renewals list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `library_renewals.view` is re-checked here. The columns are the ones
     * the listing shows. Renewals are append-only history: an export never
     * extends a due date.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', LibraryRenewal::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $renewals = LibraryRenewal::query()
            ->with([
                'issueTransaction.bookCopy.book:id,title,code',
                'issueTransaction.libraryMember.studentEnrollment.student',
                'renewer:id,name',
            ])
            ->whereIn('library_renewals.id', $ids)
            ->orderByDesc('library_renewals.renewed_on')
            ->orderByDesc('library_renewals.id')
            ->get();

        $rows = $renewals->map(fn (LibraryRenewal $renewal): array => [
            $renewal->issueTransaction?->bookCopy?->accession_number,
            $renewal->issueTransaction?->bookCopy?->book?->title,
            $renewal->issueTransaction?->libraryMember?->studentName(),
            $renewal->issueTransaction?->libraryMember?->member_code,
            $renewal->old_due_date?->format('Y-m-d'),
            $renewal->new_due_date?->format('Y-m-d'),
            $renewal->renewed_on?->format('Y-m-d'),
            $renewal->renewer?->name,
        ]);

        $audit->record('library_renewals.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $renewals->count(),
        ]);

        return CsvStreamExport::make('library-renewals-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Accession', 'Book', 'Member', 'Member code', 'Previous due', 'New due', 'Renewed on', 'Renewed by'])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LibraryRenewal::class);

        return view('library_renewals.create', [
            'issues' => LibraryFormOptions::openIssues(),
            'selectedIssueId' => $request->input('issue_transaction_id'),
        ]);
    }

    public function store(StoreLibraryRenewalRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $renewal = $this->renewals->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('library-renewals.show', $renewal)
            ->with('success', 'Issue renewed. The original issue date is unchanged.');
    }

    public function show(string $library_renewal): View
    {
        $renewal = LibraryRenewal::query()->findOrFail($library_renewal);
        $this->authorize('view', $renewal);

        $renewal->load([
            'issueTransaction.bookCopy.book',
            'issueTransaction.libraryMember.studentEnrollment.student',
            'renewer:id,name',
            'creator:id,name',
        ]);

        return view('library_renewals.show', ['renewal' => $renewal]);
    }
}
