<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelAttendanceService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\BulkHostelAttendanceRequest;
use App\Http\Requests\Hostel\StoreHostelAttendanceRequest;
use App\Http\Requests\Hostel\UpdateHostelAttendanceRequest;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hostel Attendance (Hostel Management Phase 3).
 *
 * Thin controller: college identity comes from the tenant, validation lives in
 * form requests, and residency / duplicate rules live in HostelAttendanceService.
 */
class HostelAttendanceController extends Controller
{
    public function __construct(private readonly HostelAttendanceService $attendance)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelAttendance::class);

        $filters = $request->validate([
            'attendance_date' => ['nullable', 'date'],
            'hostel_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'attendance_status' => ['nullable', Rule::in(HostelAttendance::STATUSES)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $attendances = HostelAttendance::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'allocation.hostel:id,name',
                'allocation.building:id,name',
                'allocation.room:id,room_number',
                'allocation.bed:id,bed_number',
                'allocation:id,hostel_id,hostel_building_id,hostel_room_id,hostel_bed_id,student_enrollment_id',
                'marker:id,name',
            ])
            ->when($filters['attendance_date'] ?? null, fn (Builder $query, $date) => $query->whereDate('attendance_date', $date))
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $hostelId) => $query->whereHas(
                'allocation',
                fn (Builder $allocation) => $allocation->where('hostel_id', $hostelId)
            ))
            ->when($filters['attendance_status'] ?? null, fn (Builder $query, $status) => $query->where('attendance_status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->whereHas('studentEnrollment', function (Builder $enrollment) use ($search) {
                    $enrollment->where('enrollment_number', 'like', "%{$search}%")
                        ->orWhereHas('student', function (Builder $student) use ($search) {
                            $student->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('student_number', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('hostel_attendance.index', [
            'attendances' => $attendances,
            'hostels' => HostelFormOptions::hostels(),
            'statuses' => HostelAttendance::STATUSES,
            'filters' => [
                'attendance_date' => $filters['attendance_date'] ?? '',
                'hostel_id' => $filters['hostel_id'] ?? '',
                'search' => $search,
                'attendance_status' => $filters['attendance_status'] ?? '',
            ],
        ]);
    }

    /**
     * CSV export of a bulk selection from the Hostel Attendance list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `hostel_attendance.view` is re-checked here. The columns are the
     * ones the listing shows. The listing's own bulk MARKING screen is a
     * separate mutation workflow; an export never marks, corrects or deletes
     * an attendance record.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', HostelAttendance::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $attendances = HostelAttendance::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'allocation.hostel:id,name',
                'allocation.building:id,name',
                'allocation.room:id,room_number',
                'allocation.bed:id,bed_number',
                'allocation:id,hostel_id,hostel_building_id,hostel_room_id,hostel_bed_id,student_enrollment_id',
                'marker:id,name',
            ])
            ->whereIn('hostel_attendances.id', $ids)
            ->orderByDesc('hostel_attendances.attendance_date')
            ->orderByDesc('hostel_attendances.id')
            ->get();

        $rows = $attendances->map(fn (HostelAttendance $attendance): array => [
            $attendance->attendance_date?->format('Y-m-d'),
            $attendance->studentEnrollment?->student?->student_number,
            trim(implode(' ', array_filter([
                $attendance->studentEnrollment?->student?->first_name,
                $attendance->studentEnrollment?->student?->last_name,
            ]))),
            $attendance->studentEnrollment?->enrollment_number,
            $attendance->allocation?->hostel?->name,
            $attendance->allocation?->building?->name,
            $attendance->allocation?->room?->room_number,
            $attendance->allocation?->bed?->bed_number,
            $attendance->attendance_status,
            $attendance->remarks,
            $attendance->marked_at?->format('Y-m-d H:i'),
            $attendance->marker?->name,
        ]);

        $audit->record('hostel_attendance.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $attendances->count(),
        ]);

        return CsvStreamExport::make('hostel-attendance-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Date', 'Student number', 'Student', 'Enrollment', 'Hostel', 'Building', 'Room', 'Bed', 'Status', 'Remarks', 'Marked at', 'Marked by'])
            ->streamFromCollection($rows);
    }

    public function create(): View
    {
        $this->authorize('create', HostelAttendance::class);

        return view('hostel_attendance.create', [
            'allocations' => $this->currentResidents(),
            'statuses' => HostelAttendance::STATUSES,
        ]);
    }

    public function store(StoreHostelAttendanceRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $this->attendance->create($college, $request->validated(), $request->user());

        return redirect()->route('hostel-attendance.index')->with('success', 'Hostel attendance recorded.');
    }

    public function edit(string $hostel_attendance): View
    {
        $model = $this->findScoped($hostel_attendance);
        $this->authorize('update', $model);

        return view('hostel_attendance.edit', [
            'attendance' => $model->load([
                'studentEnrollment.student',
                'allocation.hostel',
                'allocation.building',
                'allocation.room',
                'allocation.bed',
            ]),
            'statuses' => HostelAttendance::STATUSES,
        ]);
    }

    public function update(UpdateHostelAttendanceRequest $request, string $hostel_attendance): RedirectResponse
    {
        $model = $this->findScoped($hostel_attendance);
        $this->authorize('update', $model);
        $this->attendance->update($model, $request->validated(), $request->user());

        return redirect()->route('hostel-attendance.index')->with('success', 'Hostel attendance corrected.');
    }

    public function destroy(Request $request, string $hostel_attendance): RedirectResponse
    {
        $model = $this->findScoped($hostel_attendance);
        $this->authorize('delete', $model);
        $this->attendance->delete($model, $request->user());

        return redirect()->route('hostel-attendance.index')->with('success', 'Hostel attendance deleted.');
    }

    public function bulk(Request $request): View
    {
        $this->authorize('viewAny', HostelAttendance::class);

        $filters = $request->validate([
            'attendance_date' => ['nullable', 'date'],
            'hostel_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $date = $filters['attendance_date'] ?? now()->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));

        $residents = HostelAllocation::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'hostel:id,name',
                'building:id,name',
                'room:id,room_number',
                'bed:id,bed_number',
            ])
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->when($filters['hostel_id'] ?? null, fn (Builder $query, $hostelId) => $query->where('hostel_id', $hostelId))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->whereHas('studentEnrollment.student', function (Builder $student) use ($search) {
                        $student->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('student_number', 'like', "%{$search}%");
                    })->orWhereHas('studentEnrollment', fn (Builder $enrollment) => $enrollment->where('enrollment_number', 'like', "%{$search}%"));
                });
            })
            ->orderBy('hostel_id')
            ->orderBy('id')
            ->get();

        $existing = HostelAttendance::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('hostel_allocation_id', $residents->pluck('id'))
            ->get()
            ->keyBy('hostel_allocation_id');

        return view('hostel_attendance.bulk', [
            'residents' => $residents,
            'existing' => $existing,
            'hostels' => HostelFormOptions::hostels(),
            'statuses' => HostelAttendance::STATUSES,
            'filters' => [
                'attendance_date' => $date,
                'hostel_id' => $filters['hostel_id'] ?? '',
                'search' => $search,
            ],
        ]);
    }

    public function storeBulk(BulkHostelAttendanceRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $validated = $request->validated();
        $counts = $this->attendance->markBulk(
            $college,
            $validated['attendance_date'],
            $validated['records'],
            $request->user(),
        );

        return redirect()
            ->route('hostel-attendance.index', ['attendance_date' => $validated['attendance_date']])
            ->with('success', "Hostel attendance saved ({$counts['created']} marked, {$counts['updated']} corrected).");
    }

    private function findScoped(string $id): HostelAttendance
    {
        return HostelAttendance::query()->findOrFail($id);
    }

    private function currentResidents()
    {
        return HostelAllocation::query()
            ->with([
                'studentEnrollment.student:id,first_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'hostel:id,name',
                'room:id,room_number',
                'bed:id,bed_number',
            ])
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }
}
