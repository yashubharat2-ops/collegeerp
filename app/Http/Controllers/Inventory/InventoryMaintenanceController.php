<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryMaintenanceService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryMaintenanceRequest;
use App\Http\Requests\Inventory\UpdateInventoryMaintenanceRequest;
use App\Models\InventoryMaintenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asset Maintenance (Inventory / Asset Management, Phase 3).
 *
 * Maintenance events for individual assets — preventive service, repair,
 * inspection, calibration. Every record links to an EXISTING asset from the
 * Items / Assets master (no duplicate asset entity), and optional external
 * work reuses the Phase 1 vendor master. A record is a live work order: it
 * can be edited as the work walks scheduled → in progress → completed.
 * There is no delete route — records are corrected, not removed.
 *
 * Stock is never involved: maintenance is work on the item, not a movement
 * of it, so no stock ledger row is written. Tenant isolation via
 * CollegeScope + composite foreign keys.
 */
class InventoryMaintenanceController extends Controller
{
    public function __construct(private readonly InventoryMaintenanceService $maintenances)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryMaintenance::class);

        $query = InventoryMaintenance::query()
            ->with(['item:id,name,code,serial_number', 'vendor:id,name,code', 'creator:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('maintenance_type'), InventoryMaintenance::TYPES, true)) {
            $query->where('maintenance_type', $request->input('maintenance_type'));
        }

        if (in_array($request->input('status'), InventoryMaintenance::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('inventory_maintenances.index', [
            'maintenances' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::assets(),
            'vendors' => InventoryFormOptions::vendors(),
            'types' => InventoryMaintenance::TYPES,
            'statuses' => InventoryMaintenance::STATUSES,
            'filters' => [
                'item_id' => $request->input('item_id'),
                'maintenance_type' => $request->input('maintenance_type'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', InventoryMaintenance::class);

        return view('inventory_maintenances.create', [
            'items' => InventoryFormOptions::assets(),
            'vendors' => InventoryFormOptions::vendors(),
            'types' => InventoryMaintenance::TYPES,
            'statuses' => InventoryMaintenance::STATUSES,
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryMaintenanceRequest $request): RedirectResponse
    {
        $college = app(\App\Support\Tenancy\TenantContext::class)->require();

        $maintenance = $this->maintenances->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-maintenances.index', ['item_id' => $maintenance->item_id])
            ->with('success', "Maintenance \"{$maintenance->title}\" recorded for \"{$maintenance->item?->name}\".");
    }

    public function edit(string $maintenance): View
    {
        $model = $this->findScoped($maintenance);
        $this->authorize('update', $model);

        return view('inventory_maintenances.edit', [
            'maintenance' => $model->load(['item:id,name,code,serial_number', 'vendor:id,name,code']),
            'vendors' => InventoryFormOptions::vendors(),
            'types' => InventoryMaintenance::TYPES,
            'statuses' => InventoryMaintenance::STATUSES,
        ]);
    }

    public function update(UpdateInventoryMaintenanceRequest $request, string $maintenance): RedirectResponse
    {
        $updated = $this->maintenances->update($this->findScoped($maintenance), $request->validated(), $request->user());

        return redirect()
            ->route('inventory-maintenances.index')
            ->with('success', "Maintenance \"{$updated->title}\" updated.");
    }

    /**
     * Resolve a maintenance record by its route key inside the request
     * lifecycle — i.e. after the tenant middleware has resolved the active
     * college — so CollegeScope narrows the lookup to the current tenant.
     *
     * Route-model binding is deliberately NOT used here: it runs in
     * SubstituteBindings before ResolveTenant, when no tenant is active and
     * CollegeScope collapses to `whereRaw('1 = 0')`, so a record that belongs
     * to the current college would 404. Resolving here mirrors the tenant-safe
     * pattern already used by FacultyController::findScoped(): an in-tenant id
     * resolves, while a missing or cross-tenant id still 404s.
     */
    private function findScoped(string $id): InventoryMaintenance
    {
        return InventoryMaintenance::query()->findOrFail($id);
    }
}
