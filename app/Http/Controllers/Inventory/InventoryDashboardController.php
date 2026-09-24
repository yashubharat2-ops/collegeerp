<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryDashboardService;
use App\Http\Controllers\Controller;
use App\Models\InventoryDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inventory Dashboard (Inventory / Asset Management).
 *
 * Read-only overview of the Phase 1 masters — categories, items / assets and
 * vendors. Every figure is aggregated live through the tenant-scoped models
 * by InventoryDashboardService; there are no dashboard tables and nothing on
 * the screen writes. Access is gated by `inventory_dashboard.view`.
 */
class InventoryDashboardController extends Controller
{
    public function __invoke(Request $request, InventoryDashboardService $dashboard): View
    {
        $this->authorize('viewAny', InventoryDashboard::class);

        $totals = $dashboard->totals();

        return view('inventory_dashboard.index', [
            'totals' => $totals,
            'totalCategories' => $totals['categories'],
            'totalItems' => $totals['items'],
            'activeItems' => $totals['active_items'],
            'totalVendors' => $totals['vendors'],
            'itemsPerCategory' => $dashboard->itemsPerCategory(),
            'recentItems' => $dashboard->recentItems(),
        ]);
    }
}
