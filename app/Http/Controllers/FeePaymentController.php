<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Student\Filters\StudentFilter;
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
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(
        private readonly FeeCollectionService $collections,
        private readonly StudentFilter $studentFilter,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeePayment::class);

        $studentFilters = $this->studentFilter->fromRequest($request, user: $request->user());
        $query = $this->filteredQuery($request, $studentFilters)
            ->with(['studentFeeAssignment', 'studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure', 'collector'])
            // Deterministic pagination order.
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        $payments = $query->paginate(15)->withQueryString();

        return view('fee_collections.index', array_merge(FeeFormOptions::all(), $this->studentFilter->viewData($studentFilters, StudentFilter::STUDENT_LIST_FIELDS, $request->user()), [
            'payments' => $payments,
            'total' => FeeLedger::money($this->filteredQuery($request, $studentFilters)->sum('amount')),
            'selected' => $this->selectedFilters($request),
            'studentFilterInput' => $this->studentFilterInput($request),
        ]));
    }

    /**
     * CSV export of a bulk selection from the Fee Collection list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and the module
     * permission is re-checked here. Read-only by construction: collecting a fee,
     * editing a payment and cancelling a receipt each stay single-record
     * workflows with their own service rules and audit trail, so no money can
     * move from a checkbox.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', FeePayment::class);
        $studentFilters = $this->studentFilter->fromRequest($request, user: $request->user());
        $ids = ListSelection::ids($request->input('ids', []));
        $query = $this->filteredQuery($request, $studentFilters)
            ->with(['studentEnrollment.student', 'studentEnrollment.program', 'feeStructure', 'collector'])
            ->when($ids !== [], fn (Builder $builder) => $builder->whereIn('fee_payments.id', $ids))
            ->reorder('fee_payments.id', 'asc');

        $audit->record('fee_collections.exported', null, [], [
            'selected_ids' => count($ids),
            'filters' => $this->studentFilter->auditSafe($studentFilters),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('fee-collections-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Payment no', 'Date', 'Student', 'Enrollment', 'Program', 'Mode',
                'Reference', 'Amount', 'Status',
            ])
            ->map(fn (FeePayment $payment): array => [
                $payment->payment_number,
                $payment->payment_date?->format('Y-m-d'),
                $payment->studentEnrollment?->student?->fullName(),
                $payment->studentEnrollment?->enrollment_number,
                $payment->studentEnrollment?->program?->code,
                ucfirst(str_replace('_', ' ', (string) $payment->payment_mode)),
                $payment->reference_number,
                $payment->amount,
                $payment->status,
            ])
            ->streamFromQuery($query);
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

    private function filteredQuery(Request $request, array $studentFilters = []): Builder
    {
        $collegeId = app(TenantContext::class)->id();
        $query = FeePayment::query()
            // Tenant isolation is belt-and-braces: the model already carries
            // CollegeScope; this pins the join-safe column explicitly.
            ->where('fee_payments.college_id', $collegeId);
        // Use the payment's OWN enrollment stamp so tuition AND transport
        // collections (whose student_fee_assignment_id is null) filter alike.
        $this->studentFilter->applyThroughEnrollment($query, $studentFilters);

        return $query
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment', fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('student_enrollment_id'), fn (Builder $q, $value) => $q->where('student_enrollment_id', $value))
            ->when($request->input('fee_structure_id'), fn (Builder $q, $value) => $q->where('fee_structure_id', $value))
            ->when($request->input('payment_mode'), fn (Builder $q, $value) => $q->where('payment_mode', $value))
            ->when(in_array($request->input('status'), FeePayment::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->input('from'), fn (Builder $q, $value) => $q->whereDate('payment_date', '>=', $value))
            ->when($request->input('to'), fn (Builder $q, $value) => $q->whereDate('payment_date', '<=', $value));
    }

    /** @return array<string, scalar|null> */
    private function studentFilterInput(Request $request): array
    {
        $keys = ['student_number', 'admission_date_from', 'admission_date_to', 'academic_year_id', 'department_id', 'program_id', 'academic_context', 'enrollment_status', 'student_status', 'student_name', 'unique_student_id', 'email', 'apaar_id', 'enrollment_number', 'mobile_number', 'aadhaar_last4', 'father_name', 'mother_name'];
        $input = [];
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && (string) $value !== '') {
                $input[$key] = $value;
            }
        }

        return $input;
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
