<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\{TransportDashboard, TransportDriver, TransportRoute, TransportStop, Vehicle};

class TransportDashboardController extends Controller
{
    public function __invoke()
    {
        $this->authorize('viewAny', TransportDashboard::class);

        return view('transport.dashboard', ['stats' => [
            'Total Vehicles' => Vehicle::query()->count(),
            'Active Vehicles' => Vehicle::query()->where('status', 'active')->count(),
            'Total Drivers' => TransportDriver::query()->count(),
            'Active Drivers' => TransportDriver::query()->where('status', 'active')->count(),
            'Total Routes' => TransportRoute::query()->count(),
            'Total Stops' => TransportStop::query()->whereHas('route')->count(),
        ]]);
    }
}
