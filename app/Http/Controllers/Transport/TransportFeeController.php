<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Transport\Services\TransportFeeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\CollectTransportFeeRequest;
use App\Http\Requests\Transport\StoreStudentTransportFeeAssignmentRequest;
use App\Http\Requests\Transport\UpdateStudentTransportFeeAssignmentRequest;
use App\Models\{AcademicYear, FeePayment, StudentTransportAssignment, StudentTransportFeeAssignment, TransportFeeStructure, TransportRoute};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;

/**
 * Transport Fees (Transport Phase 2) — student transport fee assignments.
 *
 * The screen lists the transport fee assignments with their LIVE ledger
 * position (assigned / collected / outstanding) derived from the existing
 * Finance fee_payments rows. Collections are recorded through the existing
 * FeeCollectionService::collectTransportFee(), which writes the same
 * fee_payments rows (same payment number series and receipt projection) as
 * every other collection — there is no second payment path.
 */
class TransportFeeController extends Controller
{
    public function __construct(
        private readonly TransportFeeService $fees,
        private readonly FeeCollectionService $collections,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentTransportFeeAssignment::class);

        $query = StudentTransportFeeAssignment::query()
            ->with([
                'studentTransportAssignment.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentTransportAssignment:id,student_enrollment_id,transport_route_id,transport_stop_id',
                'studentTransportAssignment.transportRoute:id,name,code',
                'studentTransportAssignment.transportStop:id,name,code',
                'transportFeeStructure:id,name,code',
                'academicYear:id,name',
            ])
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($request->input('route_id'), fn (Builder $q, $value) => $q->whereHas('studentTransportAssignment', fn (Builder $sub) => $sub->where('transport_route_id', $value)))
            ->when($request->input('transport_fee_structure_id'), fn (Builder $q, $value) => $q->where('transport_fee_structure_id', $value))
            ->when(in_array($request->input('status'), StudentTransportFeeAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            // Deterministic pagination order.
            ->orderByDesc('id');

        $feeAssignments = $query->paginate(15)->withQueryString();
        $ledger = $this->fees->ledgerFor($feeAssignments->getCollection());
        foreach ($feeAssignments->getCollection() as $feeAssignment) {
            $feeAssignment->setAttribute('ledger', $ledger[$feeAssignment->getKey()] ?? null);
        }

        return view('transport.fees.index', array_merge($this->options(), [
            'feeAssignments' => $feeAssignments,
            'selected' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'route_id' => $request->input('route_id'),
                'transport_fee_structure_id' => $request->input('transport_fee_structure_id'),
                'status' => $request->input('status'),
            ],
        ]));
    }

    public function create(): View
    {
        $this->authorize('create', StudentTransportFeeAssignment::class);

        return view('transport.fees.create', array_merge($this->options(), $this->assignmentOptions()));
    }

    public function store(StoreStudentTransportFeeAssignmentRequest $request): RedirectResponse
    {
        $this->fees->assignFee(
            app(\App\Support\Tenancy\TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('transport-fees.index')->with('success', 'Transport fee assigned.');
    }

    public function edit(string $transport_fee): View
    {
        $feeAssignment = $this->findScoped($transport_fee);
        $this->authorize('update', $feeAssignment);

        return view('transport.fees.edit', array_merge($this->options(), $this->assignmentOptions(), [
            'feeAssignment' => $feeAssignment->load(['studentTransportAssignment.studentEnrollment.student', 'transportFeeStructure']),
            'ledger' => $this->fees->summaryFor($feeAssignment),
        ]));
    }

    public function update(UpdateStudentTransportFeeAssignmentRequest $request, string $transport_fee): RedirectResponse
    {
        $this->fees->updateFeeAssignment($this->findScoped($transport_fee), $request->validated(), $request->user());

        return redirect()->route('transport-fees.index')->with('success', 'Transport fee assignment updated.');
    }

    public function destroy(Request $request, string $transport_fee): RedirectResponse
    {
        $feeAssignment = $this->findScoped($transport_fee);
        $this->authorize('delete', $feeAssignment);

        $this->fees->deleteFeeAssignment($feeAssignment, $request->user());

        return redirect()->route('transport-fees.index')->with('success', 'Transport fee assignment deleted.');
    }

    /**
     * Record a transport fee collection through the EXISTING Finance service —
     * the resulting fee_payments row appears in Fee Collection and Receipts
     * like any other payment.
     */
    public function collect(CollectTransportFeeRequest $request, string $transport_fee): RedirectResponse
    {
        $feeAssignment = $this->findScoped($transport_fee);
        $this->authorize('collect', $feeAssignment);

        $payment = $this->collections->collectTransportFee($feeAssignment, $request->validated(), $request->user());

        return back()->with('success', 'Payment '.$payment->payment_number.' recorded.');
    }

    private function findScoped(string $id): StudentTransportFeeAssignment
    {
        return StudentTransportFeeAssignment::query()->findOrFail($id);
    }

    private function options(): array
    {
        return [
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'routes' => TransportRoute::query()->orderBy('name')->get(['id', 'name', 'code']),
            'structures' => TransportFeeStructure::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => StudentTransportFeeAssignment::STATUSES,
            'paymentModes' => FeePayment::MODES,
        ];
    }

    /** Active transport assignments available for charging. */
    private function assignmentOptions(): array
    {
        return [
            'transportAssignments' => StudentTransportAssignment::query()
                ->with([
                    'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                    'transportRoute:id,name,code',
                    'transportStop:id,name,code',
                    'academicYear:id,name',
                ])
                ->where('status', StudentTransportAssignment::STATUS_ACTIVE)
                ->orderByDesc('id')
                ->limit(500)
                ->get(),
        ];
    }
}
