<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryStockMovement;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory Transactions (Inventory / Asset Management, Phase 2 final).
 *
 * Final structure: Purchase & Stock → Inventory Transactions
 *
 * The immutable ledger behind every on-hand quantity. This is the refactored
 * version of the old "Stock Movements" screen — same table, same service,
 * same append-only guarantee, but renamed to match the final spec:
 * Purchase Order → Goods Receipt / Stock In → Inventory Transactions → Current Stock
 *
 * Stock Adjustment also generates transactions, so they appear here as well.
 *
 * No create/update/delete routes here: corrections are new movements via the
 * Goods Receipt or Stock Adjustment screens, or via PO receiving and item-form
 * quantity changes.
 */
class InventoryTransactionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewTransactions', InventoryStockMovement::class);

        $query = InventoryStockMovement::query()
            ->with(['item:id,name,code,unit', 'purchaseOrder:id,number'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('type'), InventoryStockMovement::TYPES, true)) {
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

        return view('inventory_transactions.index', [
            'movements' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::items(),
            'types' => InventoryStockMovement::TYPES,
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
     * CSV export of a bulk selection from the Inventory Transactions ledger.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `inventory_transactions.view` is re-checked here. The columns are
     * the ones the listing shows. The ledger is immutable: an export never
     * writes a movement.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewTransactions', InventoryStockMovement::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $movements = InventoryStockMovement::query()
            ->with(['item:id,name,code,unit', 'purchaseOrder:id,number', 'creator:id,name'])
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
            $movement->reference,
            $movement->reason,
            $movement->purchaseOrder?->number,
            $movement->creator?->name,
        ]);

        $audit->record('inventory_transactions.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $movements->count(),
        ]);

        return CsvStreamExport::make('inventory-transactions-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Date', 'Item', 'Item code', 'Type', 'Direction', 'Quantity', 'Unit', 'On hand after', 'Reference', 'Reason', 'PO', 'Recorded by'])
            ->streamFromCollection($rows);
    }
}
