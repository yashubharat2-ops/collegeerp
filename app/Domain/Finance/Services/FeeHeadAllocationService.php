<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Student\Filters\StudentFilter;
use App\Models\College;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preview/confirm allocation of individual FeeStructureItem (Fee Head) rows.
 *
 * A selected page is only an explicit set of candidate ids. "All filtered" is a
 * different server-side selection mode. Both modes rebuild the tenant-scoped
 * enrollment query and re-run every canonical student filter. Confirmation also
 * locks students/enrollments, rechecks eligibility and duplicates, and uses the
 * random allocation_batch_id as an idempotency key. No payment or receipt is
 * created here; each new row is only an immutable assignment snapshot.
 */
class FeeHeadAllocationService
{
    public const MAX_EXPLICIT_IDS = 200;
    public const MAX_HEADS = 50;
    public const SAMPLE_LIMIT = 200;
    private const CHUNK_SIZE = 200;

    private const AUDITED = [
        'student_enrollment_id', 'fee_structure_id', 'fee_structure_item_id',
        'fee_head_name', 'assigned_amount', 'assigned_at', 'status', 'remarks',
        'allocation_batch_id',
    ];

    public function __construct(
        private readonly StudentFilter $studentFilter,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(College $college, array $data, User $actor): array
    {
        $this->authorize($college, $actor);

        return $this->process($college, $data, $actor, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function confirm(College $college, array $data, User $actor): array
    {
        $this->authorize($college, $actor);

        return DB::transaction(function () use ($college, $data, $actor): array {
            $batchId = (string) $data['batch_id'];
            $existing = StudentFeeAssignment::withoutGlobalScopes()
                ->where('college_id', $college->getKey())
                ->where('allocation_batch_id', $batchId)
                ->with(['studentEnrollment.student'])
                ->orderBy('id')
                ->get();

            if ($existing->isNotEmpty()) {
                // A retry of an already committed batch never depends on the
                // current filter result and never creates a second charge.
                return $this->idempotentResult($existing, $data);
            }

            $result = $this->process($college, $data, $actor, true);

            $this->audit->record('student_fee_assignments.allocation_completed', null, [], [
                'batch_id' => $batchId,
                'selection_mode' => $data['selection_mode'],
                'explicit_ids' => $data['selection_mode'] === 'selected' ? count($data['student_enrollment_ids']) : 0,
                'created' => $result['created_count'],
                'skipped' => $result['skipped_count'],
                'total_amount' => $result['total_amount'],
                'filters' => $this->studentFilter->auditSafe($data['filters'] ?? []),
            ]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function process(College $college, array $data, User $actor, bool $write): array
    {
        [$structure, $heads] = $this->resolveStructureAndHeads(
            $college,
            (int) $data['fee_structure_id'],
            array_map('intval', $data['fee_head_ids']),
        );
        if (array_key_exists('head_snapshots', $data)) {
            $current = $heads->map(fn (FeeStructureItem $head) => [
                'id' => $head->getKey(), 'name' => $head->name, 'amount' => FeeLedger::money((float) $head->amount),
            ])->values()->all();
            if ($current != $data['head_snapshots']) {
                throw ValidationException::withMessages([
                    'fee_head_ids' => 'A Fee Head label or amount changed after this preview. Please preview the current amounts again.',
                ]);
            }
        }
        $filters = $data['filters'] ?? [];
        if (isset($filters['aadhaar_last4']) && ! $this->studentFilter->canFilterSensitiveIdentity($actor)) {
            abort(403, 'You are not authorized to use the Aadhaar filter.');
        }

        $selectionMode = $data['selection_mode'];
        $selectedIds = $selectionMode === 'selected'
            ? array_values(array_unique(array_map('intval', $data['student_enrollment_ids'])))
            : [];
        if (! in_array($selectionMode, ['selected', 'all_filtered'], true)) {
            throw ValidationException::withMessages(['selection_mode' => 'Choose selected rows or all filtered records.']);
        }
        if ($selectionMode === 'selected' && ($selectedIds === [] || count($selectedIds) > self::MAX_EXPLICIT_IDS)) {
            throw ValidationException::withMessages(['student_enrollment_ids' => 'Select between 1 and '.self::MAX_EXPLICIT_IDS.' enrollments.']);
        }

        $query = StudentEnrollment::withoutGlobalScopes()
            ->where('student_enrollments.college_id', $college->getKey())
            ->whereNull('student_enrollments.deleted_at')
            ->with(['student' => fn ($student) => $student->withoutGlobalScopes()->where('students.college_id', $college->getKey())]);
        $this->studentFilter->applyToEnrollments($query, $filters);
        if ($selectionMode === 'selected') {
            $query->whereIn('student_enrollments.id', $selectedIds);
        }
        $query->orderBy('student_enrollments.student_id')->orderBy('student_enrollments.id');

        $result = $this->emptyResult($data, $structure, $heads, $write);
        $seenIds = [];
        $currentStudentId = null;
        $studentAlreadyAllocated = false;

        // Offset chunks are stable here because confirmation writes assignments,
        // never the enrollment rows being paginated. Only one student's state is
        // retained across a chunk boundary; complete datasets are never loaded.
        $query->chunk(self::CHUNK_SIZE, function (Collection $enrollments) use (
            $college, $data, $actor, $structure, $heads, $write, &$result, &$seenIds,
            &$currentStudentId, &$studentAlreadyAllocated,
        ): void {
            $enrollments = $write ? $this->lockChunk($college, $enrollments) : $enrollments;
            $existing = $this->existingPayableAssignments($college, $enrollments->pluck('student_id')->all());

            foreach ($enrollments as $enrollment) {
                $seenIds[(int) $enrollment->getKey()] = true;
                $studentId = (int) $enrollment->student_id;
                if ($currentStudentId !== $studentId) {
                    $currentStudentId = $studentId;
                    $studentAlreadyAllocated = false;
                }

                if ($studentAlreadyAllocated) {
                    $this->skipAllHeads($result, $enrollment, $heads, 'another_enrollment_for_student', 'Another eligible enrollment for this student was already allocated in this batch.');
                    continue;
                }

                if (! in_array($enrollment->status, ['active', 'completed'], true)) {
                    $this->skipAllHeads($result, $enrollment, $heads, 'ineligible_enrollment', 'Cancelled or withdrawn enrollments cannot be charged.');
                    continue;
                }
                if (! $this->contextMatches($enrollment, $structure)) {
                    $this->skipAllHeads($result, $enrollment, $heads, 'academic_context_mismatch', 'The enrollment does not match the fee structure academic year/program.');
                    continue;
                }

                $studentAssignments = $existing[$studentId] ?? [];
                $wholeOverlap = false;
                $duplicateHeads = [];
                foreach ($studentAssignments as $assignment) {
                    if ((int) $assignment->fee_structure_id !== (int) $structure->getKey()) {
                        continue;
                    }
                    if ($assignment->fee_structure_item_id === null) {
                        $wholeOverlap = true;
                    } else {
                        $duplicateHeads[(int) $assignment->fee_structure_item_id] = true;
                    }
                }

                if ($wholeOverlap) {
                    $this->skipAllHeads($result, $enrollment, $heads, 'whole_structure_overlap', 'A payable whole-structure assignment already covers this student.');
                    continue;
                }

                $createdForStudent = false;
                foreach ($heads as $head) {
                    if (isset($duplicateHeads[(int) $head->getKey()])) {
                        $this->addSkip($result, $enrollment, $head, 'duplicate_fee_head', 'This Fee Head is already payable on another/current enrollment for the student.');
                        continue;
                    }

                    $amount = FeeLedger::money((float) $head->amount);
                    $row = $this->row($enrollment, $head, $amount);
                    if ($write) {
                        $assignment = new StudentFeeAssignment([
                            'college_id' => $college->getKey(),
                            'student_enrollment_id' => $enrollment->getKey(),
                            'fee_structure_id' => $structure->getKey(),
                            'fee_structure_item_id' => $head->getKey(),
                            'fee_head_name' => $head->name,
                            'assigned_amount' => $amount,
                            'assigned_at' => $data['assigned_at'],
                            'status' => $data['status'],
                            'remarks' => ($data['remarks'] ?? null) ?: null,
                            'allocation_batch_id' => $data['batch_id'],
                            'created_by' => $actor->getKey(),
                            'updated_by' => $actor->getKey(),
                        ]);
                        $assignment->save();
                        $row['assignment_id'] = $assignment->getKey();
                        $this->audit->record('student_fee_assignments.created', $assignment, [], $assignment->only(self::AUDITED));
                        // Make a duplicate within the same batch visible to the
                        // next enrollment even before the transaction commits.
                        $existing[$studentId][] = (object) [
                            'fee_structure_id' => $structure->getKey(),
                            'fee_structure_item_id' => $head->getKey(),
                        ];
                    }

                    $this->addEligible($result, $row);
                    $createdForStudent = true;
                }

                if ($createdForStudent) {
                    $studentAlreadyAllocated = true;
                    $result['student_count']++;
                }
            }
        });

        // Explicit ids that fail tenant scope, eligibility through the canonical
        // filters, or soft-delete checks are reported, never silently widened.
        if ($data['selection_mode'] === 'selected') {
            foreach ($selectedIds as $missingId) {
                if (isset($seenIds[$missingId])) {
                    continue;
                }
                foreach ($heads as $head) {
                    $result['skipped_count']++;
                    if (count($result['skips']) < self::SAMPLE_LIMIT) {
                        $result['skips'][] = [
                            'student_enrollment_id' => $missingId,
                            'student_number' => null,
                            'student_name' => null,
                            'enrollment_number' => null,
                            'fee_structure_item_id' => $head->getKey(),
                            'fee_head_name' => $head->name,
                            'reason' => 'not_in_filtered_scope',
                            'message' => 'The enrollment was not found in the re-run tenant/filter scope.',
                        ];
                    } else {
                        $result['truncated'] = true;
                    }
                }
            }
        }

        $result['total_amount'] = FeeLedger::money($result['total_cents'] / 100);
        unset($result['total_cents']);
        $result['created_count'] = $write ? $result['eligible_count'] : 0;

        return $result;
    }

    /** @return array{0: FeeStructure, 1: Collection<int, FeeStructureItem>} */
    private function resolveStructureAndHeads(College $college, int $structureId, array $headIds): array
    {
        $structure = FeeStructure::withoutGlobalScopes()
            ->where('college_id', $college->getKey())->whereNull('deleted_at')->find($structureId);
        if (! $structure) {
            throw ValidationException::withMessages(['fee_structure_id' => 'The fee structure is not in the active college.']);
        }

        $headIds = array_values(array_unique($headIds));
        if ($headIds === [] || count($headIds) > self::MAX_HEADS) {
            throw ValidationException::withMessages(['fee_head_ids' => 'Choose between 1 and '.self::MAX_HEADS.' Fee Heads.']);
        }
        $heads = FeeStructureItem::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->where('fee_structure_id', $structure->getKey())
            ->where('status', FeeStructureItem::STATUS_ACTIVE)
            ->whereIn('id', $headIds)
            ->get()
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        if ($heads->count() !== count($headIds)) {
            throw ValidationException::withMessages(['fee_head_ids' => 'Every selected Fee Head must be active and belong to the selected structure.']);
        }

        return [$structure, $heads];
    }

    private function lockChunk(College $college, Collection $enrollments): Collection
    {
        $studentIds = $enrollments->pluck('student_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $enrollmentIds = $enrollments->modelKeys();
        sort($enrollmentIds);

        Student::withoutGlobalScopes()->where('college_id', $college->getKey())
            ->whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $locked = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->getKey())
            ->whereNull('deleted_at')->whereIn('id', $enrollmentIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Preserve student/id processing order while using the locked models.
        return $enrollments->map(fn (StudentEnrollment $enrollment) => $locked->get($enrollment->getKey()) ?? $enrollment)
            ->each(fn (StudentEnrollment $enrollment) => $enrollment->setRelation('student', $enrollments->firstWhere('id', $enrollment->getKey())?->student));
    }

    /** @return array<int, array<int, object>> */
    private function existingPayableAssignments(College $college, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $rows = DB::table('student_fee_assignments')
            ->join('student_enrollments', 'student_enrollments.id', '=', 'student_fee_assignments.student_enrollment_id')
            ->where('student_fee_assignments.college_id', $college->getKey())
            ->whereIn('student_enrollments.student_id', array_unique(array_map('intval', $studentIds)))
            ->whereNull('student_enrollments.deleted_at')
            ->whereNull('student_fee_assignments.deleted_at')
            ->whereIn('student_fee_assignments.status', StudentFeeAssignment::PAYABLE_STATUSES)
            ->get([
                'student_enrollments.student_id',
                'student_fee_assignments.fee_structure_id',
                'student_fee_assignments.fee_structure_item_id',
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->student_id][] = $row;
        }

        return $grouped;
    }

    private function contextMatches(StudentEnrollment $enrollment, FeeStructure $structure): bool
    {
        return (int) $enrollment->academic_year_id === (int) $structure->academic_year_id
            && ($enrollment->program_id === null || (int) $enrollment->program_id === (int) $structure->program_id);
    }

    private function skipAllHeads(array &$result, StudentEnrollment $enrollment, Collection $heads, string $reason, string $message): void
    {
        foreach ($heads as $head) {
            $this->addSkip($result, $enrollment, $head, $reason, $message);
        }
    }

    private function addSkip(array &$result, StudentEnrollment $enrollment, FeeStructureItem $head, string $reason, string $message): void
    {
        $result['skipped_count']++;
        if (count($result['skips']) >= self::SAMPLE_LIMIT) {
            $result['truncated'] = true;
            return;
        }
        $result['skips'][] = $this->row($enrollment, $head, 0) + compact('reason', 'message');
    }

    private function addEligible(array &$result, array $row): void
    {
        $result['eligible_count']++;
        $result['total_cents'] += (int) round(((float) $row['amount']) * 100);
        if (count($result['rows']) >= self::SAMPLE_LIMIT) {
            $result['truncated'] = true;
            return;
        }
        $result['rows'][] = $row;
    }

    private function row(StudentEnrollment $enrollment, FeeStructureItem $head, float $amount): array
    {
        return [
            'student_enrollment_id' => $enrollment->getKey(),
            'student_number' => $enrollment->student?->student_number,
            'student_name' => $enrollment->student?->fullName(),
            'enrollment_number' => $enrollment->enrollment_number,
            'fee_structure_item_id' => $head->getKey(),
            'fee_head_name' => $head->name,
            'amount' => $amount,
        ];
    }

    private function emptyResult(array $data, FeeStructure $structure, Collection $heads, bool $write): array
    {
        return [
            'batch_id' => $data['batch_id'],
            'selection_mode' => $data['selection_mode'],
            'fee_structure_id' => $structure->getKey(),
            'fee_structure_name' => $structure->name,
            'heads' => $heads->map(fn (FeeStructureItem $head) => [
                'id' => $head->getKey(), 'name' => $head->name, 'amount' => FeeLedger::money((float) $head->amount),
            ])->values()->all(),
            'student_count' => 0,
            'eligible_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'total_cents' => 0,
            'total_amount' => 0.0,
            'rows' => [],
            'skips' => [],
            'truncated' => false,
            'idempotent' => false,
            'confirmed' => $write,
        ];
    }

    private function idempotentResult(Collection $existing, array $data): array
    {
        $structure = $existing->first()?->feeStructure;
        $rows = $existing->map(fn (StudentFeeAssignment $assignment) => [
            'assignment_id' => $assignment->getKey(),
            'student_enrollment_id' => $assignment->student_enrollment_id,
            'student_number' => $assignment->studentEnrollment?->student?->student_number,
            'student_name' => $assignment->studentEnrollment?->student?->fullName(),
            'enrollment_number' => $assignment->studentEnrollment?->enrollment_number,
            'fee_structure_item_id' => $assignment->fee_structure_item_id,
            'fee_head_name' => $assignment->fee_head_name,
            'amount' => (float) $assignment->assigned_amount,
        ])->values();

        return [
            'batch_id' => $data['batch_id'],
            'selection_mode' => $data['selection_mode'],
            'fee_structure_id' => $data['fee_structure_id'],
            'fee_structure_name' => $structure?->name ?? 'Fee structure',
            'heads' => [],
            'student_count' => $existing->pluck('studentEnrollment.student_id')->unique()->count(),
            'eligible_count' => 0,
            'created_count' => 0,
            'existing_count' => $existing->count(),
            'skipped_count' => 0,
            'total_amount' => FeeLedger::money((float) $existing->sum('assigned_amount')),
            'rows' => $rows->take(self::SAMPLE_LIMIT)->all(),
            'skips' => [],
            'truncated' => $existing->count() > self::SAMPLE_LIMIT,
            'idempotent' => true,
            'confirmed' => true,
        ];
    }

    private function authorize(College $college, User $actor): void
    {
        abort_unless($actor->hasPermission('student_fee_assignments.create', $college->getKey()), 403);
    }
}
