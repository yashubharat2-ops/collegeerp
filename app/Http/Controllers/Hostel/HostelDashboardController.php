<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelDashboardService;
use App\Http\Controllers\Controller;
use App\Models\HostelDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hostel Dashboard (Hostel Management).
 *
 * Read-only overview of the Phase 1 masters — hostels, buildings / blocks,
 * rooms and beds. Every figure is aggregated live through the tenant-scoped
 * models by HostelDashboardService; there are no dashboard tables and nothing
 * on the screen writes. Access is gated by `hostel_dashboard.view`.
 */
class HostelDashboardController extends Controller
{
    public function __invoke(Request $request, HostelDashboardService $dashboard): View
    {
        $this->authorize('viewAny', HostelDashboard::class);

        $totals = $dashboard->totals();

        return view('hostel_dashboard.index', [
            'totals' => $totals,
            'totalHostels' => $totals['hostels'],
            'activeHostels' => $totals['active_hostels'],
            'totalBuildings' => $totals['buildings'],
            'totalRooms' => $totals['rooms'],
            'totalBeds' => $totals['beds'],
            'availableBeds' => $totals['available_beds'],
            'occupiedBeds' => $totals['occupied_beds'],
            'perHostel' => $dashboard->perHostel(),
        ]);
    }
}
