<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryFineService;
use App\Models\{LibraryFine, LibraryTransaction};
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryFineController extends Controller
{
    public function __construct(private LibraryFineService $service) {}

    public function index()
    {
        $this->authorize('viewAny', LibraryFine::class);
        $transactions = LibraryTransaction::with(['bookCopy.book', 'libraryMember'])->whereDate('due_on', '<', now()->toDateString())->whereIn('status', [LibraryTransaction::STATUS_ISSUED, LibraryTransaction::STATUS_RETURNED])->orderBy('due_on')->orderBy('id')->get();

        return view('library_fines.index', ['fines' => LibraryFine::with('transaction.bookCopy.book', 'member')->latest()->paginate(20), 'transactions' => $transactions]);
    }

    /**
     * CSV export of a bulk selection from the Fines list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `library_fines.view` is re-checked here. Money figures are exported
     * exactly as stored — never recalculated — and the free-text payment
     * reference and remarks stay off the CSV. An export never assesses, waives,
     * modifies or pays a fine.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', LibraryFine::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $fines = LibraryFine::query()
            ->with('transaction.bookCopy.book', 'member')
            ->whereIn('library_fines.id', $ids)
            ->orderBy('library_fines.id')
            ->get();

        $rows = $fines->map(fn (LibraryFine $fine): array => [
            $fine->member?->member_code,
            $fine->transaction?->bookCopy?->book?->title,
            $fine->type,
            $fine->period_start?->format('Y-m-d'),
            $fine->period_end?->format('Y-m-d'),
            $fine->days_overdue,
            $fine->assessed_amount,
            $fine->paid_amount,
            $fine->outstanding(),
            $fine->status,
        ]);

        $audit->record('library_fines.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $fines->count(),
        ]);

        return CsvStreamExport::make('library-fines-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Member code', 'Book', 'Type', 'Period start', 'Period end', 'Days overdue', 'Assessed', 'Paid', 'Outstanding', 'Status'])
            ->streamFromCollection($rows);
    }

    public function store(Request $r)
    {
        $r->validate(['library_transaction_id' => 'required|integer', 'rate_per_day' => 'required|numeric|min:0.01']);
        $tx = LibraryTransaction::findOrFail($r->library_transaction_id);
        $this->authorize('create', LibraryFine::class);
        $fine = $this->service->assess(app(TenantContext::class)->require(), $tx, $r->user(), (float) $r->rate_per_day);

        return back()->with('success', 'Fine assessed.');
    }

    public function pay(Request $r, LibraryFine $library_fine)
    {
        $this->authorize('pay', $library_fine);
        $this->service->pay($library_fine, $r->user(), (string) $r->input('payment_reference'));

        return back()->with('success', 'Fine paid.');
    }
}
