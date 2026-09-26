<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryGoodsReceiptRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Goods Receipt / Stock In (Inventory / Asset Management, Phase 2 final).
 *
 * Final structure: Purchase & Stock → Goods Receipt / Stock In
 *
 * - Lists incoming stock movements (purchase_receipt + stock_in) for the active
 *   college, newest first. Purchase receipts are booked via Purchase Orders
 *   receive flow, but they appear here so PO → Goods Receipt → Transactions →
 *   Current Stock is visible.
 * - Allows recording a manual stock_in (no PO behind it).
 *
 * Reuses InventoryStockService and the same ledger table; no duplicate
 * business logic. Tenant isolation via CollegeScope.
 */
class InventoryGoodsReceiptController extends Controller
{
    public function __construct(private readonly InventoryStockService $stock)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewGoodsReceipts', InventoryStockMovement::class);

        $query = InventoryStockMovement::query()
            ->with(['item:id,name,code,unit', 'purchaseOrder:id,number'])
            ->whereIn('type', [
                InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                InventoryStockMovement::TYPE_STOCK_IN,
            ])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', (int) $request->input('purchase_order_id'));
        }

        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));

        if ($from !== '') {
            $query->whereDate('movement_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('movement_date', '<=', $to);
        }

        return view('inventory_goods_receipts.index', [
            'movements' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::items(),
            'filters' => [
                'item_id' => $request->input('item_id'),
                'purchase_order_id' => $request->input('purchase_order_id'),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('createGoodsReceipt', InventoryStockMovement::class);

        return view('inventory_goods_receipts.create', [
            'items' => InventoryFormOptions::items(),
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('createGoodsReceipt', InventoryStockMovement::class);

        $item = InventoryItem::query()->findOrFail($request->validated('item_id'));

        $movement = $this->stock->record($item, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-goods-receipts.index', ['item_id' => $item->getKey()])
            ->with('success', "{$movement->quantity} {$item->unit} added to \"{$item->name}\" via goods receipt. On hand is now {$movement->balance_after} {$item->unit}.");
    }
}
