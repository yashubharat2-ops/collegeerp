<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelFeeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelFeeStructureRequest;
use App\Http\Requests\Hostel\UpdateHostelFeeStructureRequest;
use App\Models\AcademicYear;
use App\Models\HostelFeeStructure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HostelFeeStructureController extends Controller
{
    public function __construct(private readonly HostelFeeService $fees)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelFeeStructure::class);

        $query = HostelFeeStructure::query()
            ->with(['academicYear:id,name'])
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when(in_array($request->input('status'), HostelFeeStructure::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when(trim((string) $request->input('search')), function (Builder $q) use ($request) {
                $search = trim((string) $request->input('search'));
                $q->where(fn (Builder $sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->orderBy('id');

        return view('hostel_fees.structures', [
            'structures' => $query->paginate(15)->withQueryString(),
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'statuses' => HostelFeeStructure::STATUSES,
            'selected' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'status' => $request->input('status'),
                'search' => trim((string) $request->input('search')),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', HostelFeeStructure::class);

        return view('hostel_fees.structure_form', $this->options() + ['record' => null]);
    }

    public function store(StoreHostelFeeStructureRequest $request): RedirectResponse
    {
        $this->fees->saveStructure(new HostelFeeStructure, $request->validated(), $request->user());

        return redirect()->route('hostel-fee-structures.index')->with('success', 'Hostel fee structure created.');
    }

    public function edit(string $fee_structure): View
    {
        $record = $this->findScoped($fee_structure);
        $this->authorize('update', $record);

        return view('hostel_fees.structure_form', $this->options() + ['record' => $record]);
    }

    public function update(UpdateHostelFeeStructureRequest $request, string $fee_structure): RedirectResponse
    {
        $this->fees->saveStructure($this->findScoped($fee_structure), $request->validated(), $request->user());

        return redirect()->route('hostel-fee-structures.index')->with('success', 'Hostel fee structure updated.');
    }

    public function destroy(Request $request, string $fee_structure): RedirectResponse
    {
        $record = $this->findScoped($fee_structure);
        $this->authorize('delete', $record);

        $this->fees->deleteStructure($record, $request->user());

        return redirect()->route('hostel-fee-structures.index')->with('success', 'Hostel fee structure deleted.');
    }

    private function findScoped(string $id): HostelFeeStructure
    {
        return HostelFeeStructure::query()->findOrFail($id);
    }

    private function options(): array
    {
        return [
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'statuses' => HostelFeeStructure::STATUSES,
            'frequencies' => HostelFeeStructure::FREQUENCIES,
        ];
    }
}
