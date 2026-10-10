<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelRoomService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelRoomRequest;
use App\Http\Requests\Hostel\UpdateHostelRoomRequest;
use App\Models\HostelRoom;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rooms (Hostel Management).
 *
 * Thin controller: validation (including contextual FK checks) lives in the
 * Form Requests, the uniqueness / hierarchy / capacity rules live in
 * HostelRoomService, and college_id always comes from the tenant context —
 * never from request data.
 */
class HostelRoomController extends Controller
{
    public function __construct(private readonly HostelRoomService $rooms)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelRoom::class);

        $query = HostelRoom::query()
            ->with(['hostel:id,name', 'building:id,hostel_id,name,code', 'building.hostel:id,name'])
            ->withCount('beds')
            // Deterministic pagination order.
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_number')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('room_number', 'like', "%{$search}%")
                    ->orWhere('room_type', 'like', "%{$search}%")
                    ->orWhereHas('building', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        if (in_array($request->input('status'), HostelRoom::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // FK filters go through the tenant-scoped query, so a foreign id
        // simply yields an empty page.
        if ($request->filled('hostel_id')) {
            $query->where('hostel_id', (int) $request->input('hostel_id'));
        }

        if ($request->filled('building_id')) {
            $query->where('building_id', (int) $request->input('building_id'));
        }

        return view('hostel_rooms.index', [
            'rooms' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => HostelRoom::STATUSES,
            'hostels' => HostelFormOptions::hostels(),
            'buildings' => HostelFormOptions::buildings(),
            'filters' => [
                'hostel_id' => $request->input('hostel_id'),
                'building_id' => $request->input('building_id'),
            ],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Rooms list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `hostel_rooms.view` is re-checked here. The bed count is the same
     * live count the listing shows; nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', HostelRoom::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $rooms = HostelRoom::query()
            ->with(['hostel:id,name', 'building:id,hostel_id,name,code', 'building.hostel:id,name'])
            ->withCount('beds')
            ->whereIn('hostel_rooms.id', $ids)
            ->orderBy('hostel_rooms.hostel_id')
            ->orderBy('hostel_rooms.building_id')
            ->orderBy('hostel_rooms.room_number')
            ->orderBy('hostel_rooms.id')
            ->get();

        $rows = $rooms->map(fn (HostelRoom $room): array => [
            $room->room_number,
            $room->building?->name,
            $room->hostel?->name,
            $room->floor,
            $room->room_type,
            $room->beds_count,
            $room->capacity,
            $room->status,
        ]);

        $audit->record('hostel_rooms.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $rooms->count(),
        ]);

        return CsvStreamExport::make('hostel-rooms-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Room', 'Building', 'Hostel', 'Floor', 'Type', 'Beds', 'Capacity', 'Status'])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HostelRoom::class);

        return view('hostel_rooms.create', [
            'statuses' => HostelRoom::STATUSES,
            'buildings' => HostelFormOptions::buildings(),
            'selectedBuildingId' => $request->input('building_id'),
        ]);
    }

    public function store(StoreHostelRoomRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $room = $this->rooms->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-rooms.index')
            ->with('success', "Room \"{$room->room_number}\" created.");
    }

    public function edit(string $room): View
    {
        $model = $this->findScoped($room);
        $this->authorize('update', $model);

        return view('hostel_rooms.edit', [
            'room' => $model->load(['hostel:id,name', 'building:id,hostel_id,name,code,floors'])->loadCount('beds'),
            'statuses' => HostelRoom::STATUSES,
        ]);
    }

    public function update(UpdateHostelRoomRequest $request, string $room): RedirectResponse
    {
        $model = $this->findScoped($room);

        $model = $this->rooms->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('hostel-rooms.index')
            ->with('success', "Room \"{$model->room_number}\" updated.");
    }

    public function destroy(string $room): RedirectResponse
    {
        $model = $this->findScoped($room);
        $this->authorize('delete', $model);

        $number = $model->room_number;
        $this->rooms->delete($model, request()->user());

        return redirect()
            ->route('hostel-rooms.index')
            ->with('success', "Room \"{$number}\" deleted.");
    }

    private function findScoped(string $id): HostelRoom
    {
        return HostelRoom::query()->findOrFail($id);
    }
}
