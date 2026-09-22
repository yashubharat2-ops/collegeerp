<?php

namespace App\Domain\Transport\Services;

use App\Models\{AcademicYear, College, StudentEnrollment, StudentTransportAssignment, TransportRoute, TransportStop, User};
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StudentTransportAssignmentService — assigns an existing StudentEnrollment to
 * an existing transport route + stop for an academic year.
 *
 * What the service guarantees (every mutation inside ONE transaction on LOCKED
 * rows, with tenant and actor fields always server-controlled):
 *
 *  - the enrollment, academic year, route and stop all belong to the ACTIVE
 *    college (checked here as well as in the Form Request — never trusted from
 *    the request);
 *  - the selected stop belongs to the selected route;
 *  - the date range is coherent (end_date >= start_date);
 *  - at most ONE active assignment may exist per (enrollment, academic year):
 *    the check runs under an enrollment row lock (concurrent requests are
 *    serialized) and is backed by a partial unique index on SQLite/Postgres;
 *  - history is preserved — completed / cancelled rows are kept, deletion is a
 *    soft delete, and only an active row blocks a new assignment.
 */
class StudentTransportAssignmentService
{
    private const DUPLICATE_MESSAGE = 'This enrollment already has an active transport assignment for the selected academic year.';

    private const AUDITED = [
        'student_enrollment_id', 'academic_year_id', 'transport_route_id', 'transport_stop_id',
        'start_date', 'end_date', 'status', 'remarks',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{student_enrollment_id: int, academic_year_id: int, transport_route_id: int, transport_stop_id: int, start_date: string, end_date?: string|null, status: string, remarks?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): StudentTransportAssignment
    {
        return DB::transaction(function () use ($college, $data, $actor): StudentTransportAssignment {
            $enrollment = $this->resolveEnrollment($college, (int) $data['student_enrollment_id']);
            $year = $this->resolveYear($college, (int) $data['academic_year_id']);
            [$route, $stop] = $this->resolveRouteAndStop($college, (int) $data['transport_route_id'], (int) $data['transport_stop_id']);

            $this->assertDateRange($data['start_date'], $data['end_date'] ?? null);

            // Serialize assignments for this enrollment so two concurrent
            // requests cannot both pass the duplicate check below.
            StudentEnrollment::withoutGlobalScopes()
                ->whereKey($enrollment->getKey())
                ->lockForUpdate()
                ->first();

            if (($data['status'] ?? 'active') === StudentTransportAssignment::STATUS_ACTIVE
                && $this->activeAssignmentExists($college->getKey(), $enrollment->getKey(), $year->getKey())) {
                throw ValidationException::withMessages(['student_enrollment_id' => self::DUPLICATE_MESSAGE]);
            }

            $assignment = new StudentTransportAssignment([
                // college_id / created_by / updated_by are stamped from the
                // server-side context — never copied from request data.
                'college_id' => $college->getKey(),
                'student_enrollment_id' => $enrollment->getKey(),
                'academic_year_id' => $year->getKey(),
                'transport_route_id' => $route->getKey(),
                'transport_stop_id' => $stop->getKey(),
                'start_date' => $data['start_date'],
                'end_date' => ($data['end_date'] ?? null) ?: null,
                'status' => $data['status'] ?? StudentTransportAssignment::STATUS_ACTIVE,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            try {
                $assignment->save();
            } catch (\Illuminate\Database\QueryException) {
                // Partial unique index (SQLite/Postgres) rejected a racing insert.
                throw ValidationException::withMessages(['student_enrollment_id' => self::DUPLICATE_MESSAGE]);
            }

            $this->audit->record('student_transport_assignments.created', $assignment, [], $assignment->only(self::AUDITED));

            return $assignment->refresh();
        });
    }

    /**
     * Update of the assignment's own fields. The student enrollment and the
     * academic year are immutable — re-pointing an assignment would rewrite
     * history; cancel it and create a new one instead.
     *
     * @param  array{transport_route_id?: int, transport_stop_id?: int, start_date?: string, end_date?: string|null, status?: string, remarks?: string|null}  $data
     */
    public function update(StudentTransportAssignment $assignment, array $data, User $actor): StudentTransportAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor): StudentTransportAssignment {
            /** @var StudentTransportAssignment $locked */
            $locked = StudentTransportAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTenant($locked, app(TenantContext::class)->id());

            $old = $locked->only(self::AUDITED);
            $college = $this->lockCollege();

            $start = $data['start_date'] ?? ($locked->start_date?->format('Y-m-d') ?: $locked->start_date);
            $end = array_key_exists('end_date', $data) ? ($data['end_date'] ?: null) : ($locked->end_date?->format('Y-m-d'));
            $this->assertDateRange((string) $start, $end);

            $status = $data['status'] ?? $locked->status;
            if ($status === StudentTransportAssignment::STATUS_ACTIVE
                && $locked->status !== StudentTransportAssignment::STATUS_ACTIVE) {
                // Re-activating must respect the duplicate guard as well.
                StudentEnrollment::withoutGlobalScopes()
                    ->whereKey($locked->student_enrollment_id)
                    ->lockForUpdate()
                    ->first();

                if ($this->activeAssignmentExists(
                    (int) $locked->college_id,
                    (int) $locked->student_enrollment_id,
                    (int) $locked->academic_year_id,
                    $locked->getKey(),
                )) {
                    throw ValidationException::withMessages(['status' => self::DUPLICATE_MESSAGE]);
                }
            }

            if (array_key_exists('transport_stop_id', $data) || array_key_exists('transport_route_id', $data)) {
                $routeId = (int) ($data['transport_route_id'] ?? $locked->transport_route_id);
                $stopId = (int) ($data['transport_stop_id'] ?? $locked->transport_stop_id);
                [$route, $stop] = $this->resolveRouteAndStop($college, $routeId, $stopId);
                $locked->transport_route_id = $route->getKey();
                $locked->transport_stop_id = $stop->getKey();
            }

            $locked->start_date = $start;
            $locked->end_date = $end ?: null;
            $locked->status = $status;
            if (array_key_exists('remarks', $data)) {
                $locked->remarks = ($data['remarks'] ?? null) ?: null;
            }
            $locked->updated_by = $actor->getKey();
            $locked->save();

            $this->audit->record('student_transport_assignments.updated', $locked, $old, $locked->only(self::AUDITED));

            return $locked->refresh();
        });
    }

    /**
     * Soft-delete the assignment (history preserved). An assignment that still
     * carries a PAYABLE transport fee assignment cannot be deleted.
     */
    public function delete(StudentTransportAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor): void {
            /** @var StudentTransportAssignment $locked */
            $locked = StudentTransportAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTenant($locked, app(TenantContext::class)->id());

            $payableFees = $locked->feeAssignments()->where('status', '!=', \App\Models\StudentTransportFeeAssignment::STATUS_CANCELLED)->exists();
            if ($payableFees) {
                throw ValidationException::withMessages([
                    'student_enrollment_id' => 'Cancel or delete this assignment’s transport fee assignments first.',
                ]);
            }

            $old = $locked->only(self::AUDITED);
            $locked->updated_by = $actor->getKey();
            $locked->save();
            $locked->delete();

            $this->audit->record('student_transport_assignments.deleted', $locked, $old, []);
        });
    }

    // ------------------------------------------------------------- helpers

    private function resolveEnrollment(College $college, int $id): StudentEnrollment
    {
        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $enrollment) {
            throw ValidationException::withMessages(['student_enrollment_id' => 'Select a student enrollment belonging to the active college.']);
        }

        return $enrollment;
    }

    private function resolveYear(College $college, int $id): AcademicYear
    {
        $year = AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $year) {
            throw ValidationException::withMessages(['academic_year_id' => 'Select an academic year belonging to the active college.']);
        }

        return $year;
    }

    /**
     * @return array{0: TransportRoute, 1: TransportStop}
     */
    private function resolveRouteAndStop(College $college, int $routeId, int $stopId): array
    {
        $route = TransportRoute::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($routeId);

        if (! $route) {
            throw ValidationException::withMessages(['transport_route_id' => 'Select a route belonging to the active college.']);
        }

        $stop = TransportStop::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->where('route_id', $route->getKey())
            ->whereNull('deleted_at')
            ->find($stopId);

        if (! $stop) {
            throw ValidationException::withMessages(['transport_stop_id' => 'The selected stop must belong to the selected route.']);
        }

        return [$route, $stop];
    }

    private function assertDateRange(string $start, ?string $end): void
    {
        if ($end !== null && $end !== '' && $end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The end date must not be before the start date.']);
        }
    }

    private function activeAssignmentExists(int $collegeId, int $enrollmentId, int $yearId, ?int $ignoreId = null): bool
    {
        return StudentTransportAssignment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_enrollment_id', $enrollmentId)
            ->where('academic_year_id', $yearId)
            ->where('status', StudentTransportAssignment::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function lockCollege(): College
    {
        return College::query()->lockForUpdate()->findOrFail(app(TenantContext::class)->require()->id);
    }

    private function assertTenant(Model $record, ?int $college): void
    {
        abort_unless($college !== null && (int) $record->college_id === $college, 403);
    }
}
