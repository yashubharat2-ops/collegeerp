<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockReportService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryStockMovement;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryStockReportController extends Controller
{
    public function index(Request $request, InventoryStockReportService $reports): View
    {
        $this->authorize('viewStockReports', InventoryStockMovement::class);

        $filters = $request->validate([
            'item_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);

        return view('inventory_stock_reports.index', [
            'rows' => $reports->activity($filters),
            'items' => InventoryFormOptions::items(),
            'filters' => array_merge(['item_id' => null, 'from' => null, 'to' => null], $filters),
        ]);
    }
}
