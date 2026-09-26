<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryStockMovement;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
}
