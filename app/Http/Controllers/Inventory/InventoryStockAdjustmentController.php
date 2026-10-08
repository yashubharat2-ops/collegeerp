<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryStockAdjustmentRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock Adjustment (Inventory / Asset Management, Phase 2 final).
 *
 * Final structure: Purchase & Stock → Stock Adjustment
 *
 * - Lists adjustments (and stock_out for backward compatibility) for the
 *   active college.
 * - Allows recording an adjustment (in or out) or a stock out. Every adjustment
 *   writes an inventory transaction with balance_after, so the ledger explains
 *   the current stock.
 *
 * Reuses InventoryStockService; no duplicate business logic.
 */
class InventoryStockAdjustmentController extends Controller
{
    public function __construct(private readonly InventoryStockService $stock)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAdjustments', InventoryStockMovement::class);

        $query = InventoryStockMovement::query()
            ->with('item:id,name,code,unit')
            ->whereIn('type', [
                InventoryStockMovement::TYPE_ADJUSTMENT,
                InventoryStockMovement::TYPE_STOCK_OUT,
            ])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('type'), [InventoryStockMovement::TYPE_ADJUSTMENT, InventoryStockMovement::TYPE_STOCK_OUT], true)) {
            $query->where('type', $request->input('type'));
        }

        if (in_array($request->input('direction'), InventoryStockMovement::DIRECTIONS, true)) {
            $query->where('direction', $request->input('direction'));
        }

        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));

        if ($from !== '') {
            $query->whereDate('movement_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('movement_date', '<=', $to);
        }

        return view('inventory_stock_adjustments.index', [
            'movements' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::items(),
            'types' => [InventoryStockMovement::TYPE_ADJUSTMENT, InventoryStockMovement::TYPE_STOCK_OUT],
            'directions' => InventoryStockMovement::DIRECTIONS,
            'filters' => [
                'item_id' => $request->input('item_id'),
                'type' => $request->input('type'),
                'direction' => $request->input('direction'),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Stock Adjustment list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `inventory_stock_adjustments.view` is re-checked here. The query is
     * narrowed to the adjustment / stock-out types the listing shows, so a
     * hand-edited URL can never widen the download past adjustments. The
     * ledger is immutable: an export never books or reverses an adjustment.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAdjustments', InventoryStockMovement::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $movements = InventoryStockMovement::query()
            ->with(['item:id,name,code,unit', 'creator:id,name'])
            ->whereIn('type', [
                InventoryStockMovement::TYPE_ADJUSTMENT,
                InventoryStockMovement::TYPE_STOCK_OUT,
            ])
            ->whereIn('inventory_stock_movements.id', $ids)
            ->orderByDesc('inventory_stock_movements.movement_date')
            ->orderByDesc('inventory_stock_movements.id')
            ->get();

        $rows = $movements->map(fn (InventoryStockMovement $movement): array => [
            $movement->movement_date?->format('Y-m-d'),
            $movement->item?->name,
            $movement->item?->code,
            $movement->type,
            $movement->direction,
            $movement->quantity,
            $movement->item?->unit,
            $movement->balance_after,
            $movement->reason,
            $movement->reference,
            $movement->creator?->name,
        ]);

        $audit->record('inventory_stock_adjustments.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $movements->count(),
        ]);

        return CsvStreamExport::make('inventory-stock-adjustments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Date', 'Item', 'Item code', 'Type', 'Direction', 'Quantity', 'Unit', 'On hand after', 'Reason', 'Reference', 'Recorded by'])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('createAdjustment', InventoryStockMovement::class);

        return view('inventory_stock_adjustments.create', [
            'items' => InventoryFormOptions::items(),
            'types' => [InventoryStockMovement::TYPE_ADJUSTMENT, InventoryStockMovement::TYPE_STOCK_OUT],
            'directions' => InventoryStockMovement::DIRECTIONS,
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryStockAdjustmentRequest $request): RedirectResponse
    {
        // Per-type ability after validation, so malformed payload gets field errors, not 403
        $ability = $request->ability();
        abort_if($ability === null, 403);
        $this->authorize($ability, InventoryStockMovement::class);

        $item = InventoryItem::query()->findOrFail($request->validated('item_id'));

        $movement = $this->stock->record($item, $request->validated(), $request->user());

        $verb = $movement->isIncoming() ? 'adjusted upward for' : 'adjusted downward for';

        return redirect()
            ->route('inventory-stock-adjustments.index', ['item_id' => $item->getKey()])
            ->with('success', "Stock {$verb} \"{$item->name}\" by {$movement->quantity} {$item->unit}. On hand is now {$movement->balance_after} {$item->unit}.");
    }
}
