<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\StudentTransportAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\StoreStudentTransportAssignmentRequest;
use App\Http\Requests\Transport\UpdateStudentTransportAssignmentRequest;
use App\Models\{AcademicYear, StudentEnrollment, StudentTransportAssignment, TransportRoute, TransportStop};
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;

/**
 * Student Transport Assignment (Transport Phase 2).
 *
 * Assigns existing student enrollments to existing transport routes/stops.
 * Everything is tenant-scoped server-side; the controller never trusts
 * college_id, created_by or updated_by from the request (the Form Requests
 * strip them and the service stamps them).
 */
class StudentTransportAssignmentController extends Controller
{
    public function __construct(private readonly StudentTransportAssignmentService $assignments)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentTransportAssignment::class);

        $query = StudentTransportAssignment::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'academicYear:id,name',
                'transportRoute:id,name,code',
                'transportStop:id,name,code,route_id,sequence',
            ])
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas('studentEnrollment', fn (Builder $sub) => $sub->where('student_id', $value)))
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->where('academic_year_id', $value))
            ->when($request->input('route_id'), fn (Builder $q, $value) => $q->where('transport_route_id', $value))
            ->when($request->input('stop_id'), fn (Builder $q, $value) => $q->where('transport_stop_id', $value))
            ->when(in_array($request->input('status'), StudentTransportAssignment::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            // Deterministic pagination order.
            ->orderByDesc('id');

        return view('transport.assignments.index', array_merge($this->options(), [
            'assignments' => $query->paginate(15)->withQueryString(),
            'selected' => [
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'route_id' => $request->input('route_id'),
                'stop_id' => $request->input('stop_id'),
                'status' => $request->input('status'),
            ],
        ]));
    }

    /**
     * CSV export of a bulk selection from the Student Transport Assignment
     * list.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `student_transport_assignments.view` is re-checked here. The columns
     * are the ones the listing shows; nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', StudentTransportAssignment::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $assignments = StudentTransportAssignment::query()
            ->with([
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment:id,student_id,enrollment_number',
                'academicYear:id,name',
                'transportRoute:id,name,code',
                'transportStop:id,name,code,route_id,sequence',
            ])
            ->whereIn('student_transport_assignments.id', $ids)
            ->orderByDesc('student_transport_assignments.id')
            ->get();

        $rows = $assignments->map(fn (StudentTransportAssignment $assignment): array => [
            $assignment->studentEnrollment?->student?->student_number,
            trim(implode(' ', array_filter([
                $assignment->studentEnrollment?->student?->first_name,
                $assignment->studentEnrollment?->student?->last_name,
            ]))),
            $assignment->studentEnrollment?->enrollment_number,
            $assignment->academicYear?->name,
            $assignment->transportRoute?->name,
            $assignment->transportStop?->name,
            $assignment->start_date?->format('Y-m-d'),
            $assignment->end_date?->format('Y-m-d'),
            $assignment->status,
        ]);

        $audit->record('student_transport_assignments.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $assignments->count(),
        ]);

        return CsvStreamExport::make('transport-assignments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Student number', 'Student', 'Enrollment', 'Academic year', 'Route', 'Stop', 'Start date', 'End date', 'Status'])
            ->streamFromCollection($rows);
    }

    public function create(): View
    {
        $this->authorize('create', StudentTransportAssignment::class);

        return view('transport.assignments.create', $this->options());
    }

    public function store(StoreStudentTransportAssignmentRequest $request): RedirectResponse
    {
        $this->assignments->create(
            app(\App\Support\Tenancy\TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('transport-assignments.index')->with('success', 'Student transport assignment created.');
    }

    public function edit(string $transport_assignment): View
    {
        $assignment = $this->findScoped($transport_assignment);
        $this->authorize('update', $assignment);

        return view('transport.assignments.edit', $this->options() + ['assignment' => $assignment]);
    }

    public function update(UpdateStudentTransportAssignmentRequest $request, string $transport_assignment): RedirectResponse
    {
        $this->assignments->update($this->findScoped($transport_assignment), $request->validated(), $request->user());

        return redirect()->route('transport-assignments.index')->with('success', 'Student transport assignment updated.');
    }

    public function destroy(Request $request, string $transport_assignment): RedirectResponse
    {
        $assignment = $this->findScoped($transport_assignment);
        $this->authorize('delete', $assignment);

        $this->assignments->delete($assignment, $request->user());

        return redirect()->route('transport-assignments.index')->with('success', 'Student transport assignment deleted.');
    }

    private function findScoped(string $id): StudentTransportAssignment
    {
        return StudentTransportAssignment::query()->findOrFail($id);
    }

    /** Tenant-scoped select-list masters (CollegeScope on every model). */
    private function options(): array
    {
        return [
            'enrollments' => StudentEnrollment::query()
                ->with('student:id,first_name,middle_name,last_name,student_number')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'student_id', 'enrollment_number', 'academic_year_id', 'status']),
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'routes' => TransportRoute::query()->orderBy('name')->get(['id', 'name', 'code']),
            'stops' => TransportStop::query()->orderBy('route_id')->orderBy('sequence')->get(['id', 'route_id', 'name', 'code', 'sequence']),
            'statuses' => StudentTransportAssignment::STATUSES,
        ];
    }
}
