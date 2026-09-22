<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\TransportFeeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\StoreTransportFeeStructureRequest;
use App\Http\Requests\Transport\UpdateTransportFeeStructureRequest;
use App\Models\{AcademicYear, TransportFeeStructure, TransportRoute, TransportStop};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;

/**
 * Transport Fee Structures (transport fee categories — Transport Phase 2).
 *
 * The transport-side pricing master behind the Transport Fees screen. Amounts
 * are configured per college; nothing is hard-coded and no money is collected
 * or stored here (collections live in the existing Finance module).
 */
class TransportFeeStructureController extends Controller
{
    public function __construct(private readonly TransportFeeService $fees)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TransportFeeStructure::class);

        $query = TransportFeeStructure::query()
            ->with(['academicYear:id,name', 'transportRoute:id,name,code', 'transportStop:id,name,code'])
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($request->input('route_id'), fn (Builder $q, $value) => $q->where('transport_route_id', $value))
            ->when(in_array($request->input('status'), TransportFeeStructure::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when(trim((string) $request->input('search')), function (Builder $q) use ($request): void {
                $search = trim((string) $request->input('search'));
                $q->where(fn (Builder $sub) => $sub
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"));
            })
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        return view('transport.fees.structures', array_merge($this->options(), [
            'structures' => $query->paginate(15)->withQueryString(),
            'selected' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'route_id' => $request->input('route_id'),
                'status' => $request->input('status'),
                'search' => trim((string) $request->input('search')),
            ],
        ]));
    }

    public function create(): View
    {
        $this->authorize('create', TransportFeeStructure::class);

        return view('transport.fees.structure_form', $this->options() + ['record' => null]);
    }

    public function store(StoreTransportFeeStructureRequest $request): RedirectResponse
    {
        $this->fees->saveStructure(new TransportFeeStructure, $request->validated(), $request->user());

        return redirect()->route('transport-fee-structures.index')->with('success', 'Transport fee structure created.');
    }

    public function edit(string $transport_fee_structure): View
    {
        $record = $this->findScoped($transport_fee_structure);
        $this->authorize('update', $record);

        return view('transport.fees.structure_form', $this->options() + ['record' => $record]);
    }

    public function update(UpdateTransportFeeStructureRequest $request, string $transport_fee_structure): RedirectResponse
    {
        $this->fees->saveStructure($this->findScoped($transport_fee_structure), $request->validated(), $request->user());

        return redirect()->route('transport-fee-structures.index')->with('success', 'Transport fee structure updated.');
    }

    public function destroy(Request $request, string $transport_fee_structure): RedirectResponse
    {
        $record = $this->findScoped($transport_fee_structure);
        $this->authorize('delete', $record);

        $this->fees->deleteStructure($record, $request->user());

        return redirect()->route('transport-fee-structures.index')->with('success', 'Transport fee structure deleted.');
    }

    private function findScoped(string $id): TransportFeeStructure
    {
        return TransportFeeStructure::query()->findOrFail($id);
    }

    private function options(): array
    {
        return [
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'routes' => TransportRoute::query()->orderBy('name')->get(['id', 'name', 'code']),
            'stops' => TransportStop::query()->orderBy('route_id')->orderBy('sequence')->get(['id', 'route_id', 'name', 'code', 'sequence']),
            'statuses' => TransportFeeStructure::STATUSES,
        ];
    }
}
