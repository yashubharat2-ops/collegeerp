<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeePayment;
use App\Models\FeeReceipt;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Receipts (Finance / Fees).
 *
 * A receipt is a printable projection of a successful FeePayment — there is no
 * receipts table and no second amount. The receipt number is the payment's own
 * server-generated payment_number, unique per college.
 *
 * Only completed, non-cancelled collections are ever listed or rendered: a
 * receipt must never show money that was reversed. That rule lives in
 * FeeReceiptPolicy (authorization) and in the query below, never in the view.
 *
 * No PDF package is used: the print page relies on the project's existing print
 * stylesheet and the browser's print dialog.
 */
class FeeReceiptController extends Controller
{
    public function __construct(private readonly FeeDuesService $dues)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeReceipt::class);

        $query = $this->issuedQuery($request)
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure', 'collector'])
            // Deterministic pagination order.
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        $receipts = $query->paginate(15)->withQueryString();

        return view('receipts.index', array_merge(FeeFormOptions::all(), [
            'college' => app(TenantContext::class)->college(),
            'receipts' => $receipts,
            'total' => FeeLedger::money($this->issuedQuery($request)->sum('amount')),
            'selected' => [
                'search' => $request->input('search'),
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'program_id' => $request->input('program_id'),
                'payment_mode' => $request->input('payment_mode'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
        ]));
    }

    public function show(string $fee_payment): View
    {
        $receipt = FeeReceipt::fromPayment($this->findScoped($fee_payment));
        $this->authorize('view', $receipt);

        return view('receipts.show', $this->receiptData($receipt));
    }

    /**
     * Print-friendly receipt. Gated on receipts.print in addition to the
     * never-show-a-cancelled-payment rule.
     */
    public function print(string $fee_payment): View
    {
        $receipt = FeeReceipt::fromPayment($this->findScoped($fee_payment));
        $this->authorize('print', $receipt);

        return view('receipts.print', $this->receiptData($receipt));
    }

    /**
     * Completed, non-cancelled collections of the active college only.
     */
    private function issuedQuery(Request $request): Builder
    {
        return FeePayment::query()
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            // Filters run through the payment's OWN enrollment stamp so tuition
            // AND transport collections filter identically (transport payments
            // have no student_fee_assignment_id).
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($request->input('program_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('payment_mode'), fn (Builder $q, $value) => $q->where('payment_mode', $value))
            ->when($request->input('from'), fn (Builder $q, $value) => $q->whereDate('payment_date', '>=', $value))
            ->when($request->input('to'), fn (Builder $q, $value) => $q->whereDate('payment_date', '<=', $value))
            ->when(trim((string) $request->input('search')), function (Builder $q) use ($request): void {
                $search = trim((string) $request->input('search'));
                $q->where(function (Builder $sub) use ($search): void {
                    $sub->where('payment_number', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%");
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptData(FeeReceipt $receipt): array
    {
        $payment = $receipt->payment;
        $payment->load(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure.items', 'transportFeeAssignment.transportFeeStructure', 'collector', 'refunds']);

        $tuitionAssignment = $payment->studentFeeAssignment()->first();
        $transportAssignment = $payment->transportFeeAssignment()->first();

        return [
            'college' => app(TenantContext::class)->college(),
            'receipt' => $receipt,
            'payment' => $payment,
            // The balance position is computed server-side from the same shared
            // ledger arithmetic the Due / Outstanding screen uses — for both
            // tuition and transport assignments. Never from the browser.
            'ledger' => match (true) {
                $tuitionAssignment !== null => $this->dues->summaryFor($tuitionAssignment),
                $transportAssignment !== null => app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($transportAssignment),
                default => null,
            },
        ];
    }

    private function findScoped(string $id): FeePayment
    {
        return FeePayment::query()->findOrFail($id);
    }
}
