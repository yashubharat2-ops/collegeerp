<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelAllocationService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelAllocationRequest;
use App\Http\Requests\Hostel\UpdateHostelAllocationRequest;
use App\Http\Requests\Hostel\VacateHostelAllocationRequest;
use App\Models\AcademicYear;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hostel Allocations (Hostel Management Phase 2).
 *
 * Thin controller: validation lives in Form Requests, hierarchy / tenancy /
 * occupancy rules live in HostelAllocationService, college_id always from
 * tenant context.
 */
class HostelAllocationController extends Controller
{
    public function __construct(private readonly HostelAllocationService $allocations)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelAllocation::class);

        $query = HostelAllocation::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'academicYear:id,name',
                'hostel:id,name',
                'building:id,name',
                'room:id,room_number',
                'bed:id,bed_number',
            ])
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($request->input('hostel_id'), fn (Builder $q, $value) => $q->where('hostel_id', $value))
            ->when($request->input('hostel_building_id'), fn (Builder $q, $value) => $q->where('hostel_building_id', $value))
            ->when($request->input('hostel_room_id'), fn (Builder $q, $value) => $q->where('hostel_room_id', $value))
            ->when($request->input('hostel_bed_id'), fn (Builder $q, $value) => $q->where('hostel_bed_id', $value))
            ->when(in_array($request->input('status'), HostelAllocation::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when(trim((string) $request->input('search')), function (Builder $q, $search) {
                $search = trim($search);
                $q->where(function (Builder $sub) use ($search) {
                    $sub->whereHas('studentEnrollment.student', fn (Builder $s) => $s
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('student_number', 'like', "%{$search}%"))
                        ->orWhereHas('studentEnrollment', fn (Builder $s) => $s->where('enrollment_number', 'like', "%{$search}%"));
                });
            })
            ->when($request->input('student_enrollment_id'), fn (Builder $q, $value) => $q->where('student_enrollment_id', $value))
            ->orderByDesc('allocation_date')
            ->orderByDesc('id');

        return view('hostel_allocations.index', [
            'allocations' => $query->paginate(15)->withQueryString(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'hostels' => HostelFormOptions::hostels(),
            'buildings' => HostelFormOptions::buildings(),
            'rooms' => HostelFormOptions::rooms(),
            'beds' => HostelFormOptions::beds(),
            'statuses' => HostelAllocation::STATUSES,
            'filters' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'hostel_id' => $request->input('hostel_id'),
                'hostel_building_id' => $request->input('hostel_building_id'),
                'hostel_room_id' => $request->input('hostel_room_id'),
                'hostel_bed_id' => $request->input('hostel_bed_id'),
                'status' => $request->input('status'),
                'search' => trim((string) $request->input('search')),
                'student_enrollment_id' => $request->input('student_enrollment_id'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', HostelAllocation::class);

        return view('hostel_allocations.create', [
            'statuses' => HostelAllocation::STATUSES,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'hostels' => HostelFormOptions::hostels(),
            'buildings' => HostelFormOptions::buildings(),
            'rooms' => HostelFormOptions::rooms(),
            'beds' => HostelFormOptions::beds(),
            'enrollments' => $this->enrollmentOptions(),
            'selected' => [
                'academic_year_id' => $request->input('academic_year_id'),
                'hostel_id' => $request->input('hostel_id'),
            ],
        ]);
    }

    public function store(StoreHostelAllocationRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $allocation = $this->allocations->create($college, $request->validated(), $request->user());

        return redirect()->route('hostel-allocations.index')->with('success', 'Hostel allocation created.');
    }

    public function show(string $allocation): View
    {
        $model = $this->findScoped($allocation);
        $this->authorize('view', $model);

        return view('hostel_allocations.show', [
            'allocation' => $model->load([
                'studentEnrollment.student',
                'academicYear',
                'hostel',
                'building',
                'room',
                'bed',
            ]),
        ]);
    }

    public function edit(string $allocation): View
    {
        $model = $this->findScoped($allocation);
        $this->authorize('update', $model);

        return view('hostel_allocations.edit', [
            'allocation' => $model->load(['studentEnrollment.student', 'academicYear', 'hostel', 'building', 'room', 'bed']),
            'statuses' => HostelAllocation::STATUSES,
        ]);
    }

    public function update(UpdateHostelAllocationRequest $request, string $allocation): RedirectResponse
    {
        $model = $this->findScoped($allocation);

        $this->allocations->update($model, $request->validated(), $request->user());

        return redirect()->route('hostel-allocations.index')->with('success', 'Hostel allocation updated.');
    }

    public function vacate(VacateHostelAllocationRequest $request, string $allocation): RedirectResponse
    {
        $model = $this->findScoped($allocation);

        $this->allocations->vacate($model, $request->validated(), $request->user());

        return redirect()->route('hostel-allocations.index')->with('success', 'Hostel allocation vacated — bed released.');
    }

    public function cancel(Request $request, string $allocation): RedirectResponse
    {
        $model = $this->findScoped($allocation);
        $this->authorize('cancel', $model);

        $this->allocations->cancel($model, $request->user(), $request->input('remarks'));

        return redirect()->route('hostel-allocations.index')->with('success', 'Hostel allocation cancelled — bed released.');
    }

    public function destroy(Request $request, string $allocation): RedirectResponse
    {
        $model = $this->findScoped($allocation);
        $this->authorize('delete', $model);

        $this->allocations->delete($model, $request->user());

        return redirect()->route('hostel-allocations.index')->with('success', 'Hostel allocation deleted.');
    }

    private function findScoped(string $id): HostelAllocation
    {
        return HostelAllocation::query()->findOrFail($id);
    }

    private function enrollmentOptions()
    {
        return StudentEnrollment::query()
            ->with(['student:id,first_name,last_name,student_number', 'academicYear:id,name'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }
}
