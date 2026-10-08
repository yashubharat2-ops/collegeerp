<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryAssignmentService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryAssetReturnRequest;
use App\Models\InventoryAssignment;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asset Return (Inventory / Asset Management, Phase 3).
 *
 * Lists the assets currently out (the ACTIVE assignment rows) and records
 * their return. A return only updates the assignment row — it flips the
 * status to `returned` and stamps the return date, actor and notes — so the
 * historical assignment is preserved in place, and the same asset can be
 * assigned again later as a NEW row. No delete, no stock movement: the
 * asset goes back to the college, it does not leave the catalogue.
 *
 * Reuses the existing assignment rows of the Asset Assignment module — no
 * duplicate return entity. The module is gated by its OWN permission family
 * (`inventory_asset_returns.*`, answered by the `viewReturns` / `returnAsset`
 * abilities of the assignment policy). Tenant isolation via CollegeScope +
 * composite foreign key.
 */
class InventoryAssetReturnController extends Controller
{
    public function __construct(private readonly InventoryAssignmentService $assignments)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewReturns', InventoryAssignment::class);

        $query = InventoryAssignment::query()
            ->active()
            ->with(['item:id,name,code,serial_number', 'assignee', 'creator:id,name'])
            ->orderByDesc('assigned_on')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        return view('inventory_asset_returns.index', [
            'assignments' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::assets(),
            'filters' => [
                'item_id' => $request->input('item_id'),
            ],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Asset Return list (the assets
     * currently out).
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `inventory_asset_returns.view` is re-checked here. The query is
     * narrowed to ACTIVE assignments exactly like the listing, so a returned
     * assignment can never ride along in an asset-return export. The assignee
     * name is resolved with the model's own accessor. An export never records
     * a return.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewReturns', InventoryAssignment::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $assignments = InventoryAssignment::query()
            ->active()
            ->with(['item:id,name,code,serial_number', 'assignee', 'creator:id,name'])
            ->whereIn('inventory_assignments.id', $ids)
            ->orderByDesc('inventory_assignments.assigned_on')
            ->orderByDesc('inventory_assignments.id')
            ->get();

        $rows = $assignments->map(fn (InventoryAssignment $assignment): array => [
            $assignment->item?->name,
            $assignment->item?->code,
            $assignment->item?->serial_number,
            $assignment->assigneeName(),
            $assignment->assigned_to_type,
            $assignment->purpose,
            $assignment->assigned_on?->format('Y-m-d'),
            $assignment->creator?->name,
        ]);

        $audit->record('inventory_asset_returns.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $assignments->count(),
        ]);

        return CsvStreamExport::make('inventory-asset-returns-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Asset', 'Item code', 'Serial', 'Assignee', 'Assignee type', 'Purpose', 'Assigned on', 'Assigned by'])
            ->streamFromCollection($rows);
    }

    public function store(StoreInventoryAssetReturnRequest $request): RedirectResponse
    {
        $assignment = InventoryAssignment::query()->findOrFail($request->validated('assignment_id'));

        $this->authorize('returnAsset', $assignment);

        $returned = $this->assignments->returnAsset($assignment, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-asset-returns.index')
            ->with('success', "\"{$returned->item?->name}\" returned by {$returned->returner?->name} on {$returned->returned_on->format('d M Y')}. The assignment history is preserved.");
    }
}
