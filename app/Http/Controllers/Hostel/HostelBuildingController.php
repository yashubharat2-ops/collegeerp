<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelBuildingService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelBuildingRequest;
use App\Http\Requests\Hostel\UpdateHostelBuildingRequest;
use App\Models\HostelBuilding;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buildings / Blocks (Hostel Management).
 *
 * Thin controller: validation (including contextual FK checks) lives in the
 * Form Requests, the uniqueness / parent / tenant rules live in
 * HostelBuildingService, and college_id always comes from the tenant
 * context — never from request data.
 */
class HostelBuildingController extends Controller
{
    public function __construct(private readonly HostelBuildingService $buildings)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelBuilding::class);

        $query = HostelBuilding::query()
            ->with('hostel:id,name,code')
            ->withCount(['rooms', 'beds'])
            // Deterministic pagination order.
            ->orderBy('hostel_id')
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), HostelBuilding::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // The FK filter goes through the tenant-scoped query, so a foreign id
        // simply yields an empty page.
        if ($request->filled('hostel_id')) {
            $query->where('hostel_id', (int) $request->input('hostel_id'));
        }

        return view('hostel_buildings.index', [
            'buildings' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => HostelBuilding::STATUSES,
            'hostels' => HostelFormOptions::hostels(),
            'filters' => [
                'hostel_id' => $request->input('hostel_id'),
            ],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Buildings / Blocks list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `hostel_buildings.view` is re-checked here. The room / bed counts
     * are the same live counts the listing shows; nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', HostelBuilding::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $buildings = HostelBuilding::query()
            ->with('hostel:id,name,code')
            ->withCount(['rooms', 'beds'])
            ->whereIn('hostel_buildings.id', $ids)
            ->orderBy('hostel_buildings.hostel_id')
            ->orderBy('hostel_buildings.name')
            ->orderBy('hostel_buildings.id')
            ->get();

        $rows = $buildings->map(fn (HostelBuilding $building): array => [
            $building->name,
            $building->code,
            $building->hostel?->name,
            $building->floors,
            $building->rooms_count,
            $building->beds_count,
            $building->status,
        ]);

        $audit->record('hostel_buildings.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $buildings->count(),
        ]);

        return CsvStreamExport::make('hostel-buildings-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Building', 'Code', 'Hostel', 'Floors', 'Rooms', 'Beds', 'Status'])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HostelBuilding::class);

        return view('hostel_buildings.create', [
            'statuses' => HostelBuilding::STATUSES,
            'hostels' => HostelFormOptions::hostels(),
            'selectedHostelId' => $request->input('hostel_id'),
        ]);
    }

    public function store(StoreHostelBuildingRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $building = $this->buildings->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-buildings.index')
            ->with('success', "Building \"{$building->name}\" created.");
    }

    public function edit(string $building): View
    {
        $model = $this->findScoped($building);
        $this->authorize('update', $model);

        return view('hostel_buildings.edit', [
            'building' => $model->load(['hostel:id,name,code'])->loadCount(['rooms', 'beds']),
            'statuses' => HostelBuilding::STATUSES,
        ]);
    }

    public function update(UpdateHostelBuildingRequest $request, string $building): RedirectResponse
    {
        $model = $this->findScoped($building);

        $model = $this->buildings->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-buildings.index')
            ->with('success', "Building \"{$model->name}\" updated.");
    }

    public function destroy(string $building): RedirectResponse
    {
        $model = $this->findScoped($building);
        $this->authorize('delete', $model);

        $name = $model->name;
        $this->buildings->delete($model, request()->user());

        return redirect()
            ->route('hostel-buildings.index')
            ->with('success', "Building \"{$name}\" deleted.");
    }

    private function findScoped(string $id): HostelBuilding
    {
        return HostelBuilding::query()->findOrFail($id);
    }
}
