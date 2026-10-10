<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryGoodsReceiptRequest;
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

    /**
     * CSV export of a bulk selection from the Goods Receipt / Stock In list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `inventory_goods_receipts.view` is re-checked here. The query is
     * narrowed to the incoming movement types the listing shows, so a
     * hand-edited URL can never widen the download past goods receipts. The
     * ledger is immutable: an export never books or reverses a movement.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewGoodsReceipts', InventoryStockMovement::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $movements = InventoryStockMovement::query()
            ->with(['item:id,name,code,unit', 'purchaseOrder:id,number', 'creator:id,name'])
            ->whereIn('type', [
                InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                InventoryStockMovement::TYPE_STOCK_IN,
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
            $movement->quantity,
            $movement->item?->unit,
            $movement->balance_after,
            $movement->reference,
            $movement->purchaseOrder?->number,
            $movement->reason,
            $movement->creator?->name,
        ]);

        $audit->record('inventory_goods_receipts.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $movements->count(),
        ]);

        return CsvStreamExport::make('inventory-goods-receipts-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Date', 'Item', 'Item code', 'Type', 'Quantity', 'Unit', 'On hand after', 'Reference', 'PO', 'Reason', 'Recorded by'])
            ->streamFromCollection($rows);
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
