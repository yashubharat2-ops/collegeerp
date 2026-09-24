<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Hostel\Services\HostelFeeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\CollectHostelFeeRequest;
use App\Http\Requests\Hostel\StoreHostelFeeAssignmentRequest;
use App\Http\Requests\Hostel\UpdateHostelFeeAssignmentRequest;
use App\Models\AcademicYear;
use App\Models\FeePayment;
use App\Models\HostelAllocation;
use App\Models\HostelFeeAssignment;
use App\Models\HostelFeeStructure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hostel Fees (Hostel Management Phase 2) — hostel fee assignments.
 *
 * Lists assignments with LIVE ledger position derived from existing Finance
 * fee_payments rows. Collections are recorded through existing
 * FeeCollectionService::collectHostelFee(), same payment number series and
 * receipt projection as every other collection.
 */
class HostelFeeController extends Controller
{
    public function __construct(
        private readonly HostelFeeService $fees,
        private readonly FeeCollectionService $collections,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelFeeAssignment::class);

        $query = HostelFeeAssignment::query()
            ->with([
                'hostelAllocation.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'hostelAllocation:id,student_enrollment_id,hostel_id,hostel_bed_id',
                'hostelAllocation.hostel:id,name',
                'hostelAllocation.bed:id,bed_number',
                'feeStructure:id,name,code',
                'academicYear:id,name',
            ])
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($request->input('hostel_fee_structure_id'), fn (Builder $q, $value) => $q->where('hostel_fee_structure_id', $value))
            ->when($request->input('hostel_allocation_id'), fn (Builder $q, $value) => $q->where('hostel_allocation_id', $value))
            ->when(in_array($request->input('status'), HostelFeeAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id');

        $feeAssignments = $query->paginate(15)->withQueryString();
        $ledger = $this->fees->ledgerFor($feeAssignments->getCollection());
        foreach ($feeAssignments->getCollection() as $feeAssignment) {
            $feeAssignment->setAttribute('ledger', $ledger[$feeAssignment->getKey()] ?? null);
        }

        return view('hostel_fees.index', array_merge($this->options(), [
            'feeAssignments' => $feeAssignments,
            'selected' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'hostel_fee_structure_id' => $request->input('hostel_fee_structure_id'),
                'hostel_allocation_id' => $request->input('hostel_allocation_id'),
                'status' => $request->input('status'),
            ],
        ]));
    }

    public function create(): View
    {
        $this->authorize('create', HostelFeeAssignment::class);

        return view('hostel_fees.create', array_merge($this->options(), $this->assignmentOptions()));
    }

    public function store(StoreHostelFeeAssignmentRequest $request): RedirectResponse
    {
        $this->fees->assignFee(
            app(\App\Support\Tenancy\TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('hostel-fees.index')->with('success', 'Hostel fee assigned.');
    }

    public function edit(string $fee): View
    {
        $feeAssignment = $this->findScoped($fee);
        $this->authorize('update', $feeAssignment);

        return view('hostel_fees.edit', array_merge($this->options(), $this->assignmentOptions(), [
            'feeAssignment' => $feeAssignment->load(['hostelAllocation.studentEnrollment.student', 'feeStructure']),
            'ledger' => $this->fees->summaryFor($feeAssignment),
        ]));
    }

    public function update(UpdateHostelFeeAssignmentRequest $request, string $fee): RedirectResponse
    {
        $this->fees->updateFeeAssignment($this->findScoped($fee), $request->validated(), $request->user());

        return redirect()->route('hostel-fees.index')->with('success', 'Hostel fee assignment updated.');
    }

    public function destroy(Request $request, string $fee): RedirectResponse
    {
        $feeAssignment = $this->findScoped($fee);
        $this->authorize('delete', $feeAssignment);

        $this->fees->deleteFeeAssignment($feeAssignment, $request->user());

        return redirect()->route('hostel-fees.index')->with('success', 'Hostel fee assignment deleted.');
    }

    public function collect(CollectHostelFeeRequest $request, string $fee): RedirectResponse
    {
        $feeAssignment = $this->findScoped($fee);
        $this->authorize('collect', $feeAssignment);

        $payment = $this->collections->collectHostelFee($feeAssignment, $request->validated(), $request->user());

        return back()->with('success', 'Payment '.$payment->payment_number.' recorded.');
    }

    private function findScoped(string $id): HostelFeeAssignment
    {
        return HostelFeeAssignment::query()->findOrFail($id);
    }

    private function options(): array
    {
        return [
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'structures' => HostelFeeStructure::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => HostelFeeAssignment::STATUSES,
            'paymentModes' => FeePayment::MODES,
        ];
    }

    private function assignmentOptions(): array
    {
        return [
            'allocations' => HostelAllocation::query()
                ->with([
                    'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                    'hostel:id,name',
                    'bed:id,bed_number',
                    'academicYear:id,name',
                ])
                ->whereIn('status', [HostelAllocation::STATUS_ACTIVE, HostelAllocation::STATUS_VACATED])
                ->orderByDesc('id')
                ->limit(500)
                ->get(),
        ];
    }
}
