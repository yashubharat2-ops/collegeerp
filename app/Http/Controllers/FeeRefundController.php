<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeRefundService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Http\Requests\FeeRefund\StoreFeeRefundRequest;
use App\Http\Requests\FeeRefund\UpdateFeeRefundRequest;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Refunds (Finance / Fees).
 *
 * A refund always references an actual collection: the form selects an existing
 * payment, and the refundable amount is computed server-side from that payment
 * minus its valid refunds, under a row lock. Refund numbers are generated
 * server-side; approval and processing are separate, audited actions.
 *
 * There is deliberately no delete route: refund records are never removed.
 */
class FeeRefundController extends Controller
{
    public function __construct(private readonly FeeRefundService $refunds)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeRefund::class);

        $query = FeeRefund::query()
            ->with(['payment.studentEnrollment.student', 'payment.studentEnrollment.program', 'payment.feeStructure', 'approver', 'processor'])
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($request->input('program_id'), fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($request->input('fee_payment_id'), fn (Builder $q, $value) => $q->where('fee_payment_id', $value))
            ->when(in_array($request->input('status'), FeeRefund::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->input('from'), fn (Builder $q, $value) => $q->whereDate('refund_date', '>=', $value))
            ->when($request->input('to'), fn (Builder $q, $value) => $q->whereDate('refund_date', '<=', $value))
            // Deterministic pagination order.
            ->orderByDesc('id');

        return view('refunds.index', array_merge(FeeFormOptions::all(), [
            'refunds' => $query->paginate(15)->withQueryString(),
            'selected' => [
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'program_id' => $request->input('program_id'),
                'fee_payment_id' => $request->input('fee_payment_id'),
                'status' => $request->input('status'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
        ]));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', FeeRefund::class);

        $payment = null;

        if ($paymentId = $request->input('fee_payment_id')) {
            $payment = FeePayment::query()
                ->with(['studentEnrollment.student', 'studentEnrollment.program', 'feeStructure'])
                ->find($paymentId);
        }

        return view('refunds.create', array_merge(FeeFormOptions::all(), [
            'payment' => $payment,
            'selectedPaymentId' => $paymentId,
            // Only completed collections can be refunded, so cancelled and
            // soft-deleted payments are never offered here.
            'payments' => FeePayment::query()
                ->where('status', FeePayment::STATUS_COMPLETED)
                ->with('studentEnrollment.student')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'payment_number', 'amount', 'student_enrollment_id', 'status']),
        ]));
    }

    public function store(StoreFeeRefundRequest $request): RedirectResponse
    {
        app(TenantContext::class)->require();

        $payment = FeePayment::query()->findOrFail($request->validated()['fee_payment_id']);

        $refund = $this->refunds->create($payment, $request->validated(), $request->user());

        return redirect()
            ->route('refunds.index')
            ->with('success', "Refund {$refund->refund_number} recorded (pending approval).");
    }

    public function edit(string $refund): View
    {
        $model = $this->findScoped($refund);
        $this->authorize('update', $model);

        $model->load(['payment.studentEnrollment.student', 'payment.studentEnrollment.program', 'payment.feeStructure']);

        return view('refunds.edit', array_merge(FeeFormOptions::all(), [
            'refund' => $model,
        ]));
    }

    public function update(UpdateFeeRefundRequest $request, string $refund): RedirectResponse
    {
        $model = $this->findScoped($refund);

        $this->refunds->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('refunds.index')
            ->with('success', 'Refund updated.');
    }

    public function approve(string $refund): RedirectResponse
    {
        $model = $this->findScoped($refund);
        $this->authorize('approve', $model);

        $this->refunds->approve($model, request()->user());

        return redirect()
            ->route('refunds.index')
            ->with('success', "Refund {$model->refund_number} approved.");
    }

    public function process(string $refund): RedirectResponse
    {
        $model = $this->findScoped($refund);
        $this->authorize('process', $model);

        $this->refunds->process($model, request()->user());

        return redirect()
            ->route('refunds.index')
            ->with('success', "Refund {$model->refund_number} processed.");
    }

    private function findScoped(string $id): FeeRefund
    {
        return FeeRefund::query()->findOrFail($id);
    }
}
