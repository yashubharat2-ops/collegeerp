<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryRenewalService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\LibraryRenewal\StoreLibraryRenewalRequest;
use App\Models\LibraryRenewal;
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
