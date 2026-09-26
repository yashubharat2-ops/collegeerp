<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryStockAdjustmentRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
