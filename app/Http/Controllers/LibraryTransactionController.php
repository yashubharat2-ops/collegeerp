<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryTransactionService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\LibraryTransaction\LoseLibraryTransactionRequest;
use App\Http\Requests\LibraryTransaction\ReturnLibraryTransactionRequest;
use App\Http\Requests\LibraryTransaction\StoreLibraryTransactionRequest;
use App\Http\Requests\LibraryTransaction\UpdateLibraryTransactionRequest;
use App\Models\LibraryTransaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Issue / Return (Library Management).
 *
 * Issues an available copy to an active member and records the return or loss.
 * There is no destroy action: the register is the circulation history.
 */
class LibraryTransactionController extends Controller
{
    public function __construct(private readonly LibraryTransactionService $transactions)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LibraryTransaction::class);

        $query = LibraryTransaction::query()
            ->with([
                'bookCopy.book:id,title,code',
                'libraryMember.studentEnrollment.student',
            ])
            ->withCount('renewals')
            ->orderByDesc('issued_on')
            ->orderByDesc('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereHas('bookCopy', function ($copy) use ($needle): void {
                    $copy->where('accession_number', 'like', $needle)
                        ->orWhere('barcode', 'like', $needle)
                        ->orWhereHas('book', fn ($book) => $book->where('title', 'like', $needle));
                })->orWhereHas('libraryMember', function ($member) use ($needle): void {
                    $member->where('member_code', 'like', $needle)
                        ->orWhereHas('studentEnrollment.student', function ($student) use ($needle): void {
                            $student->where('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle);
                        });
                });
            });
        }

        if (in_array($request->input('status'), LibraryTransaction::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('overdue')) {
            $query->where('status', LibraryTransaction::STATUS_ISSUED)
                ->whereDate('due_on', '<', now()->toDateString());
        }

        if ($request->filled('library_member_id')) {
            $query->where('library_member_id', (int) $request->input('library_member_id'));
        }

        if ($request->filled('book_copy_id')) {
            $query->where('book_copy_id', (int) $request->input('book_copy_id'));
        }

        return view('library_transactions.index', [
            'transactions' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'overdue' => $request->boolean('overdue'),
            'statuses' => LibraryTransaction::STATUSES,
            'filters' => [
                'library_member_id' => $request->input('library_member_id'),
                'book_copy_id' => $request->input('book_copy_id'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LibraryTransaction::class);

        return view('library_transactions.create', [
            'copies' => LibraryFormOptions::availableCopies(),
            'members' => LibraryFormOptions::borrowableMembers(),
            'selectedCopyId' => $request->input('book_copy_id'),
            'selectedMemberId' => $request->input('library_member_id'),
        ]);
    }

    public function store(StoreLibraryTransactionRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $transaction = $this->transactions->issue($college, $request->validated(), $request->user());

        return redirect()
            ->route('library-transactions.show', $transaction)
            ->with('success', 'Book copy issued.');
    }

    public function show(string $library_transaction): View
    {
        $transaction = $this->findScoped($library_transaction);
        $this->authorize('view', $transaction);

        $transaction->load([
            'bookCopy.book',
            'libraryMember.studentEnrollment.student',
            'libraryMember.studentEnrollment.academicYear',
            'issuer:id,name',
            'returner:id,name',
            'renewals.renewer:id,name',
        ]);

        return view('library_transactions.show', ['transaction' => $transaction]);
    }

    public function edit(string $library_transaction): View
    {
        $transaction = $this->findScoped($library_transaction);
        $this->authorize('update', $transaction);

        return view('library_transactions.edit', [
            'transaction' => $transaction->load(['bookCopy.book', 'libraryMember.studentEnrollment.student']),
        ]);
    }

    public function update(UpdateLibraryTransactionRequest $request, string $library_transaction): RedirectResponse
    {
        $transaction = $this->findScoped($library_transaction);
        $transaction = $this->transactions->update($transaction, $request->validated(), $request->user());

        return redirect()
            ->route('library-transactions.show', $transaction)
            ->with('success', 'Issue remarks updated.');
    }

    public function returnCopy(ReturnLibraryTransactionRequest $request, string $library_transaction): RedirectResponse
    {
        $transaction = $this->findScoped($library_transaction);
        $transaction = $this->transactions->returnCopy($transaction, $request->validated(), $request->user());

        return redirect()
            ->route('library-transactions.show', $transaction)
            ->with('success', 'Book copy returned and marked available.');
    }

    public function markLost(LoseLibraryTransactionRequest $request, string $library_transaction): RedirectResponse
    {
        $transaction = $this->findScoped($library_transaction);
        $transaction = $this->transactions->markLost($transaction, $request->validated(), $request->user());

        return redirect()
            ->route('library-transactions.show', $transaction)
            ->with('success', 'Issue marked lost. The copy is no longer available.');
    }

    private function findScoped(string $id): LibraryTransaction
    {
        return LibraryTransaction::query()->findOrFail($id);
    }
}
