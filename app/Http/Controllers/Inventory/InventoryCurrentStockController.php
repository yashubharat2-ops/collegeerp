<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryCurrentStockController extends Controller
{
    public function index(Request $request, InventoryStockBalanceService $stock): View
    {
        $this->authorize('viewCurrentStock', InventoryStockMovement::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'item_type' => ['nullable', Rule::in(InventoryItem::TYPES)],
            'status' => ['nullable', Rule::in(InventoryItem::STATUSES)],
        ]);

        $query = $stock->itemsWithBalance()->with('category:id,name,code');
        $search = trim($filters['search'] ?? '');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('inventory_items.name', 'like', "%{$search}%")
                    ->orWhere('inventory_items.code', 'like', "%{$search}%")
                    ->orWhere('inventory_items.serial_number', 'like', "%{$search}%");
            });
        }

        foreach (['category_id', 'item_type', 'status'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where("inventory_items.{$filter}", $filters[$filter]);
            }
        }

        return view('inventory_current_stock.index', [
            'items' => $query->orderBy('inventory_items.name')->orderBy('inventory_items.id')->paginate(20)->withQueryString(),
            'categories' => InventoryFormOptions::categories(),
            'types' => InventoryItem::TYPES,
            'statuses' => InventoryItem::STATUSES,
            'filters' => array_merge(['category_id' => null, 'item_type' => null, 'status' => null], $filters, ['search' => $search]),
        ]);
    }
}
