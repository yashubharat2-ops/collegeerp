<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
use App\Http\Requests\FeePayment\CancelFeePaymentRequest;
use App\Http\Requests\FeePayment\StoreFeePaymentRequest;
use App\Http\Requests\FeePayment\UpdateFeePaymentRequest;
use App\Models\FeePayment;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fee Collection (Finance / Fees).
 *
 * This is the only screen that moves money in. Amounts, payment numbers and the
 * collected_by stamp are all server-controlled; the outstanding cap is enforced
 * inside FeeCollectionService under an assignment row lock, so the browser can
 * never submit a balance, a payment number or an amount that exceeds what is
 * payable.
 */
class FeePaymentController extends Controller
{
    public function __construct(private readonly FeeCollectionService $collections)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeePayment::class);

        $query = $this->filteredQuery($request)
            ->with(['studentFeeAssignment', 'studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure', 'collector'])
            // Deterministic pagination order.
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        $payments = $query->paginate(15)->withQueryString();

        return view('fee_collections.index', array_merge(FeeFormOptions::all(), [
            'payments' => $payments,
            'total' => FeeLedger::money($this->filteredQuery($request)->sum('amount')),
            'selected' => $this->selectedFilters($request),
        ]));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', FeePayment::class);

        $assignment = null;

        if ($assignmentId = $request->input('student_fee_assignment_id')) {
            $assignment = StudentFeeAssignment::query()
                ->with(['studentEnrollment.student', 'studentEnrollment.program', 'feeStructure'])
                ->find($assignmentId);
        }

        return view('fee_collections.create', array_merge(FeeFormOptions::all(), [
            'assignment' => $assignment,
            'selectedAssignmentId' => $assignmentId,
        ]));
    }

    public function store(StoreFeePaymentRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $assignment = StudentFeeAssignment::query()->findOrFail($request->validated()['student_fee_assignment_id']);

        $payment = $this->collections->collect($assignment, $request->validated(), $request->user());

        return redirect()
            ->route('fee-collections.index')
            ->with('success', "Payment {$payment->payment_number} recorded.");
    }

    public function edit(string $fee_collection): View
    {
        $payment = $this->findScoped($fee_collection);
        $this->authorize('update', $payment);

        $payment->load(['studentEnrollment.student', 'studentEnrollment.program', 'feeStructure', 'collector', 'refunds']);

        return view('fee_collections.edit', array_merge(FeeFormOptions::all(), [
            'payment' => $payment,
        ]));
    }

    public function update(UpdateFeePaymentRequest $request, string $fee_collection): RedirectResponse
    {
        $payment = $this->findScoped($fee_collection);

        $this->collections->update($payment, $request->validated(), $request->user());

        return redirect()
            ->route('fee-collections.index')
            ->with('success', "Payment {$payment->payment_number} updated.");
    }

    /**
     * Reverse a collection (audited; the row is kept and excluded from balances).
     */
    public function cancel(CancelFeePaymentRequest $request, string $fee_collection): RedirectResponse
    {
        $payment = $this->findScoped($fee_collection);

        $payment = $this->collections->cancel($payment, $request->user(), $request->validated()['cancellation_reason'] ?? null);

        return redirect()
            ->route('fee-collections.index')
            ->with('success', "Payment {$payment->payment_number} cancelled.");
    }

    public function destroy(string $fee_collection): RedirectResponse
    {
        $payment = $this->findScoped($fee_collection);
        $this->authorize('delete', $payment);

        $number = $payment->payment_number;
        $this->collections->delete($payment, request()->user());

        return redirect()
            ->route('fee-collections.index')
            ->with('success', "Payment {$number} deleted.");
    }

    private function filteredQuery(Request $request): Builder
    {
        $collegeId = app(TenantContext::class)->id();

        return FeePayment::query()
            // Tenant isolation is belt-and-braces: the model already carries
            // CollegeScope; this pins the join-safe column explicitly.
            ->where('fee_payments.college_id', $collegeId)
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($request->input('program_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($request->input('student_enrollment_id'), fn (Builder $q, $value) => $q->where('student_enrollment_id', $value))
            ->when($request->input('fee_structure_id'), fn (Builder $q, $value) => $q->where('fee_structure_id', $value))
            ->when($request->input('payment_mode'), fn (Builder $q, $value) => $q->where('payment_mode', $value))
            ->when(in_array($request->input('status'), FeePayment::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->input('from'), fn (Builder $q, $value) => $q->whereDate('payment_date', '>=', $value))
            ->when($request->input('to'), fn (Builder $q, $value) => $q->whereDate('payment_date', '<=', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedFilters(Request $request): array
    {
        $selected = [];

        foreach (['student_id', 'academic_year_id', 'program_id', 'student_enrollment_id', 'fee_structure_id', 'payment_mode', 'status', 'from', 'to'] as $field) {
            $selected[$field] = $request->input($field);
        }

        return $selected;
    }

    private function findScoped(string $id): FeePayment
    {
        return FeePayment::query()->findOrFail($id);
    }
}
