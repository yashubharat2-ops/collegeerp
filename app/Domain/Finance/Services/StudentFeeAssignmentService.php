<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\College;
use App\Models\FeeStructure;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StudentFeeAssignmentService — assigns an existing FeeStructure to one existing
 * StudentEnrollment.
 *
 * What the service guarantees:
 *
 *  - the enrollment and the fee structure both belong to the ACTIVE college;
 *  - the structure is contextually valid for the enrollment (same academic year,
 *    and the same program whenever the enrollment carries one);
 *  - the assigned amount is COMPUTED SERVER-SIDE from the structure's active
 *    components and frozen on the assignment — a browser-supplied amount is
 *    never read, and later edits to the fee structure never re-price a student
 *    who was already assigned a plan;
 *  - the same structure cannot be assigned twice to the same enrollment while an
 *    assignment exists, enforced under an enrollment row lock (so concurrent
 *    requests are serialized) and backed by a partial unique index;
 *  - an enrollment that was cancelled or withdrawn can never be charged;
 *  - nothing about the student's academic context (student, year, program, term)
 *    is copied here: it stays owned by StudentEnrollment.
 */
class StudentFeeAssignmentService
{
    private const DUPLICATE_MESSAGE = 'This fee structure is already assigned to the selected enrollment.';

    /** Enrollments that may carry a payable assignment. */
    private const ASSIGNABLE_ENROLLMENT_STATUSES = ['active', 'completed'];

    private const AUDITED = [
        'student_enrollment_id',
        'fee_structure_id',
        'assigned_amount',
        'assigned_at',
        'status',
        'remarks',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{student_enrollment_id: int, fee_structure_id: int, assigned_at: string, status: string, remarks?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): StudentFeeAssignment
    {
        return DB::transaction(function () use ($college, $data, $actor): StudentFeeAssignment {
            $enrollment = $this->resolveEnrollment($college, (int) $data['student_enrollment_id']);
            $structure = $this->resolveStructure($college, (int) $data['fee_structure_id']);

            $this->assertContextMatches($enrollment, $structure);

            // Serialize assignments for this enrollment so two concurrent
            // requests cannot both pass the duplicate check below.
            StudentEnrollment::withoutGlobalScopes()
                ->whereKey($enrollment->getKey())
                ->lockForUpdate()
                ->first();

            if ($this->activeAssignmentExists($college->getKey(), $enrollment->getKey(), $structure->getKey())) {
                throw ValidationException::withMessages(['fee_structure_id' => self::DUPLICATE_MESSAGE]);
            }

            $assignedAmount = FeeLedger::assignedFromItems($structure->allItems()->get());

            if ($assignedAmount <= 0) {
                throw ValidationException::withMessages([
                    'fee_structure_id' => 'The selected fee structure has no active fee components and cannot be assigned.',
                ]);
            }

            $assignment = new StudentFeeAssignment([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'student_enrollment_id' => $enrollment->getKey(),
                'fee_structure_id' => $structure->getKey(),
                'assigned_amount' => $assignedAmount,
                'assigned_at' => $data['assigned_at'],
                'status' => $data['status'],
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            try {
                $assignment->save();
            } catch (QueryException) {
                // Partial unique index (SQLite/Postgres) rejected a racing insert.
                throw ValidationException::withMessages(['fee_structure_id' => self::DUPLICATE_MESSAGE]);
            }

            $this->audit->record('student_fee_assignments.created', $assignment, [], $assignment->only(self::AUDITED));

            return $assignment->refresh();
        });
    }

    /**
     * Update of the assignment's own bookkeeping fields.
     *
     * The fee structure and the assigned amount are immutable: re-pricing an
     * existing assignment would silently rewrite a student's financial history.
     * Cancel the assignment and create a new one instead.
     *
     * @param  array{assigned_at?: string, status?: string, remarks?: string|null}  $data
     */
    public function update(StudentFeeAssignment $assignment, array $data, User $actor): StudentFeeAssignment
    {
        $this->assertTenant($assignment);

        return DB::transaction(function () use ($assignment, $data, $actor): StudentFeeAssignment {
            $old = $assignment->only(self::AUDITED);

            foreach (['assigned_at', 'status', 'remarks'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $assignment->{$field} = $field === 'remarks' ? ($data[$field] ?: null) : $data[$field];
            }

            $assignment->updated_by = $actor->getKey();
            $assignment->save();

            $this->audit->record('student_fee_assignments.updated', $assignment, $old, $assignment->only(self::AUDITED));

            return $assignment->refresh();
        });
    }

    /**
     * Soft delete. The audit trail and every collection recorded against the
     * assignment stay intact; the assignment simply stops being visible.
     */
    public function delete(StudentFeeAssignment $assignment, User $actor): void
    {
        $this->assertTenant($assignment);

        DB::transaction(function () use ($assignment): void {
            $snapshot = $assignment->only(self::AUDITED);
            $assignment->delete();

            $this->audit->record('student_fee_assignments.deleted', $assignment, $snapshot, []);
        });
    }

    // ------------------------------------------------------------- helpers

    private function resolveEnrollment(College $college, int $enrollmentId): StudentEnrollment
    {
        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($enrollmentId);

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'student_enrollment_id' => 'The selected enrollment does not belong to the active college.',
            ]);
        }

        if (! in_array($enrollment->status, self::ASSIGNABLE_ENROLLMENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'student_enrollment_id' => 'A cancelled or withdrawn enrollment cannot be charged.',
            ]);
        }

        return $enrollment;
    }

    private function resolveStructure(College $college, int $structureId): FeeStructure
    {
        $structure = FeeStructure::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($structureId);

        if (! $structure) {
            throw ValidationException::withMessages([
                'fee_structure_id' => 'The selected fee structure does not belong to the active college.',
            ]);
        }

        return $structure;
    }

    /**
     * The fee structure must belong to the enrollment's academic context.
     *
     * The academic year is always required to match. The program must match
     * whenever the enrollment carries one (StudentEnrollment.program_id is
     * nullable); when it does not, the structure's program defines the plan.
     */
    private function assertContextMatches(StudentEnrollment $enrollment, FeeStructure $structure): void
    {
        if ((int) $structure->academic_year_id !== (int) $enrollment->academic_year_id) {
            throw ValidationException::withMessages([
                'fee_structure_id' => 'The selected fee structure belongs to a different academic year than the enrollment.',
            ]);
        }

        if ($enrollment->program_id !== null && (int) $structure->program_id !== (int) $enrollment->program_id) {
            throw ValidationException::withMessages([
                'fee_structure_id' => 'The selected fee structure belongs to a different program than the enrollment.',
            ]);
        }
    }

    /**
     * A duplicate is an assignment that still carries a payable balance: the
     * same plan may be re-assigned to the same enrollment only after the earlier
     * assignment was cancelled (or deleted), which keeps the history intact while
     * still blocking double charging.
     */
    private function activeAssignmentExists(int $collegeId, int $enrollmentId, int $structureId): bool
    {
        return StudentFeeAssignment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_enrollment_id', $enrollmentId)
            ->where('fee_structure_id', $structureId)
            ->whereIn('status', StudentFeeAssignment::PAYABLE_STATUSES)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function assertTenant(StudentFeeAssignment $assignment): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $assignment->college_id === (int) $collegeId, 403);
    }
}
