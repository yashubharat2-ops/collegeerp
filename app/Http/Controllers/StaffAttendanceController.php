<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\StaffAttendanceService;
use App\Http\Requests\StaffAttendance\StoreStaffAttendanceRequest;
use App\Http\Requests\StaffAttendance\UpdateStaffAttendanceRequest;
use App\Models\Faculty;
use App\Models\StaffAttendance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffAttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StaffAttendance::class);
        $filters = $request->validate([
            'faculty_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(StaffAttendance::STATUSES)],
        ]);
        $query = StaffAttendance::query()->with('employee')->orderByDesc('attendance_date')->orderBy('faculty_id');
        if ($filters['faculty_id'] ?? null) $query->where('faculty_id', $filters['faculty_id']);
        if ($filters['from'] ?? null) $query->whereDate('attendance_date', '>=', $filters['from']);
        if ($filters['to'] ?? null) $query->whereDate('attendance_date', '<=', $filters['to']);
        if ($filters['status'] ?? null) $query->where('status', $filters['status']);

        return view('staff_attendance.index', [
            'attendances' => $query->paginate(20)->withQueryString(),
            'employees' => $this->employees(),
            'statuses' => StaffAttendance::STATUSES,
            'filters' => $filters,
        ]);
    }

    /**
     * CSV export of a bulk selection from the Staff Attendance list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and
     * `staff_attendance.view` is re-checked here. Read-only: correcting a day's
     * attendance stays a single-record workflow with its own controller action
     * and audit trail.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', StaffAttendance::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $attendances = StaffAttendance::query()
            ->with('employee')
            ->whereIn('staff_attendances.id', $ids)
            ->orderBy('staff_attendances.id')
            ->get();

        $rows = $attendances->map(fn (StaffAttendance $attendance): array => [
            $attendance->attendance_date?->format('Y-m-d'),
            $attendance->employee?->full_name,
            $attendance->employee?->employee_code,
            $attendance->status,
            $attendance->remarks,
        ]);

        $audit->record('staff_attendance.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $attendances->count(),
        ]);

        return CsvStreamExport::make('staff-attendance-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Date', 'Employee', 'Employee code', 'Status', 'Remarks'])
            ->streamFromCollection($rows);
    }

    public function create(): View
    {
        $this->authorize('create', StaffAttendance::class);
        return view('staff_attendance.create', ['employees' => $this->employees(), 'statuses' => StaffAttendance::STATUSES]);
    }

    public function store(StoreStaffAttendanceRequest $request, StaffAttendanceService $service): RedirectResponse
    {
        $service->create($request->validated(), $this->collegeId(), auth()->id());
        return redirect()->route('staff-attendance.index')->with('success', 'Staff attendance recorded.');
    }

    public function edit(string $staffAttendance): View
    {
        $model = $this->findScoped($staffAttendance);
        $this->authorize('update', $model);
        return view('staff_attendance.edit', ['attendance' => $model, 'employees' => $this->employees(), 'statuses' => StaffAttendance::STATUSES]);
    }

    public function update(UpdateStaffAttendanceRequest $request, string $staffAttendance, StaffAttendanceService $service): RedirectResponse
    {
        $model = $this->findScoped($staffAttendance);
        $this->authorize('update', $model);
        $service->update($model, $request->validated(), auth()->id());
        return redirect()->route('staff-attendance.index')->with('success', 'Staff attendance corrected.');
    }

    public function destroy(string $staffAttendance, StaffAttendanceService $service): RedirectResponse
    {
        $model = $this->findScoped($staffAttendance);
        $this->authorize('delete', $model);
        $service->delete($model, auth()->id());
        return redirect()->route('staff-attendance.index')->with('success', 'Staff attendance deleted.');
    }

    private function findScoped(string $id): StaffAttendance
    {
        return StaffAttendance::query()->findOrFail($id);
    }

    private function employees()
    {
        return Faculty::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
    }

    private function collegeId(): int { return (int) app(TenantContext::class)->require()->getKey(); }
}
