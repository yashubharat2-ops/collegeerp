<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryStockMovement;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryLowStockController extends Controller
{
    public function index(Request $request, InventoryStockBalanceService $stock): View
    {
        $this->authorize('viewLowStock', InventoryStockMovement::class);

        $filters = $request->validate([
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $threshold = number_format((float) ($filters['threshold'] ?? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD), 2, '.', '');
        $search = trim($filters['search'] ?? '');
        $query = $stock->lowStock($threshold)->with('category:id,name,code');

        if (! empty($filters['category_id'])) {
            $query->where('inventory_items.category_id', $filters['category_id']);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('inventory_items.name', 'like', "%{$search}%")
                    ->orWhere('inventory_items.code', 'like', "%{$search}%");
            });
        }

        return view('inventory_low_stock.index', [
            'items' => $query->orderBy('inventory_items.name')->orderBy('inventory_items.id')->paginate(20)->withQueryString(),
            'categories' => InventoryFormOptions::categories(),
            'filters' => ['category_id' => $filters['category_id'] ?? null, 'search' => $search, 'threshold' => $threshold],
        ]);
    }
}
