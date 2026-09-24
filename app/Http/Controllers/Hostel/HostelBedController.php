<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelBedService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelBedRequest;
use App\Http\Requests\Hostel\UpdateHostelBedRequest;
use App\Models\HostelBed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Beds (Hostel Management).
 *
 * Thin controller: validation (including contextual FK checks) lives in the
 * Form Requests, the uniqueness / hierarchy / capacity rules live in
 * HostelBedService, and college_id always comes from the tenant context —
 * never from request data.
 *
 * The bed master deliberately has no student allocation: future Hostel
 * Allocation (Phase 2) connects existing StudentEnrollment records to beds
 * and becomes the source of truth for occupancy.
 */
class HostelBedController extends Controller
{
    public function __construct(private readonly HostelBedService $beds)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelBed::class);

        $query = HostelBed::query()
            ->with(['hostel:id,name', 'building:id,name', 'room:id,room_number'])
            // Deterministic pagination order.
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_id')
            ->orderBy('bed_number')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('bed_number', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($r) => $r->where('room_number', 'like', "%{$search}%"))
                    ->orWhereHas('building', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        if (in_array($request->input('status'), HostelBed::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // FK filters go through the tenant-scoped query, so a foreign id
        // simply yields an empty page.
        foreach (['hostel_id', 'building_id', 'room_id'] as $fk) {
            if ($request->filled($fk)) {
                $query->where($fk, (int) $request->input($fk));
            }
        }

        return view('hostel_beds.index', [
            'beds' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => HostelBed::STATUSES,
            'hostels' => HostelFormOptions::hostels(),
            'buildings' => HostelFormOptions::buildings(),
            'rooms' => HostelFormOptions::rooms(),
            'filters' => [
                'hostel_id' => $request->input('hostel_id'),
                'building_id' => $request->input('building_id'),
                'room_id' => $request->input('room_id'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HostelBed::class);

        return view('hostel_beds.create', [
            'statuses' => HostelBed::STATUSES,
            'rooms' => HostelFormOptions::rooms(),
            'selectedRoomId' => $request->input('room_id'),
        ]);
    }

    public function store(StoreHostelBedRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $bed = $this->beds->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-beds.index')
            ->with('success', "Bed \"{$bed->bed_number}\" created.");
    }

    public function edit(string $bed): View
    {
        $model = $this->findScoped($bed);
        $this->authorize('update', $model);

        return view('hostel_beds.edit', [
            'bed' => $model->load(['hostel:id,name', 'building:id,name', 'room:id,room_number']),
            'statuses' => HostelBed::STATUSES,
        ]);
    }

    public function update(UpdateHostelBedRequest $request, string $bed): RedirectResponse
    {
        $model = $this->findScoped($bed);

        $model = $this->beds->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-beds.index')
            ->with('success', "Bed \"{$model->bed_number}\" updated.");
    }

    public function destroy(string $bed): RedirectResponse
    {
        $model = $this->findScoped($bed);
        $this->authorize('delete', $model);

        $number = $model->bed_number;
        $this->beds->delete($model, request()->user());

        return redirect()
            ->route('hostel-beds.index')
            ->with('success', "Bed \"{$number}\" deleted.");
    }

    private function findScoped(string $id): HostelBed
    {
        return HostelBed::query()->findOrFail($id);
    }
}
