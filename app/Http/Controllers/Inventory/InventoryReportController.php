<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryReportService;
use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryReportController extends Controller
{
    public function index(Request $request, InventoryReportService $reports): View
    {
        $this->authorize('viewReports', InventoryItem::class);

        $filters = $request->validate([
            'category_id' => ['nullable', 'integer', 'min:1'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        $threshold = number_format((float) ($filters['threshold'] ?? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD), 2, '.', '');

        return view('inventory_reports.index', [
            'rows' => $reports->categories($threshold, $filters['category_id'] ?? null),
            'categories' => InventoryFormOptions::categories(),
            'filters' => ['category_id' => $filters['category_id'] ?? null, 'threshold' => $threshold],
        ]);
    }
}
