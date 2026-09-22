<?php

namespace App\Http\Controllers\Transport;

use App\Models\TransportStop;
use Illuminate\Http\Request;

class TransportStopController extends TransportMasterController
{
    public string $model = TransportStop::class;
    public string $title = 'Stops';
    public string $routeName = 'transport-stops';

    /**
     * Flat, cross-route stop listing for the "Stops" navigation entry.
     *
     * Per-route stop management (create/edit/delete) stays in the nested
     * route-scoped actions inherited from TransportMasterController — stops are
     * always owned by exactly one route. This read-only index lists every stop
     * of the active college (tenant-scoped) with its route, ordered
     * deterministically (route, then sequence).
     */
    public function indexAll(Request $request)
    {
        $this->authorize('viewAny', TransportStop::class);

        $search = trim((string) $request->input('search'));
        $query = TransportStop::query()->with('route:id,name,code');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('landmark', 'like', "%{$search}%");
            });
        }
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->input('route_id'));
        }
        if (in_array($request->input('status'), TransportStop::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('transport.stops', [
            'title' => 'Stops',
            'routeName' => $this->routeName,
            'statuses' => TransportStop::STATUSES,
            'routes' => \App\Models\TransportRoute::query()->orderBy('name')->get(['id', 'name', 'code']),
            'records' => $query->orderBy('route_id')->orderBy('sequence')->orderBy('id')->paginate(15)->withQueryString(),
        ]);
    }
}
