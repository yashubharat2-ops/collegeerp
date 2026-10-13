<?php

namespace App\Domain\Student\Actions;

use App\Domain\Student\Services\StudentAcademicRecordService;
use App\Domain\Student\Services\StudentService;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Models\StudentTransfer;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student promotion: request → approve.
 *
 * Promotion is ADDITIVE and never destructive:
 *
 * - approving creates a NEW StudentEnrollment for the target academic year
 *   (server-generated enrollment number, via StudentService),
 * - the source enrollment is only flipped to `completed` when it was `active` —
 *   its row, enrollment number and audit trail are preserved, and a `completed`
 *   or `cancelled` source is never silently rewritten,
 * - nothing is deleted, and the decision itself is stored on StudentPromotion
 *   so it stays auditable after the fact,
 * - approving also records the outcome on the student's progression ledger
 *   (`student_academic_records.promotion_status` for the SOURCE period) so the
 *   ledger and the decision cannot drift apart — see
 *   {@see recordPromotionOutcomeOnSourceLedger()} for exactly how narrow that write is.
 *
 * NO progression rule is encoded here: which academic year, program, term and
 * section a student moves into is the operator's choice, so annual promotion,
 * lateral entry and re-admission all work without special casing.
 *
 * SEMESTER PROGRESSION IS NOT SUPPORTED, and this is a schema fact rather than
 * a policy choice. `student_enrollments` has no `academic_term_id` column, so
 * the enrollment created on approval records the academic YEAR only — the
 * `target_academic_term_id` chosen here is stored on the promotion decision and
 * audited, but is NOT carried onto the enrollment. It is a reference annotation
 * on the decision, not a term of study, and the create form says so. Two
 * consequences follow, both intended:
 *
 * - the enrollment tuple (student + academic year + program) means promoting a
 *   student twice into the SAME academic year and program — semester 1 then
 *   semester 2 — is refused as a duplicate target, not once but twice (see
 *   {@see assertNoTargetEnrollment()} and StudentService::hasNonDeletedEnrollmentForTuple),
 * - nothing downstream may read the term off the enrollment to infer which
 *   semester a student is in; use StudentAcademicRecord, which does carry a
 *   term.
 *
 * ELIGIBILITY is enforced by one shared guard, {@see assertPromotable()}, which
 * BOTH request() and approve() call: a student with an issued Transfer
 * Certificate, a withdrawn student, or a withdrawn source enrollment can never
 * be promoted. Requesting is therefore refused up front for a promotion that
 * approval would have to reject, and a request that became ineligible after it
 * was recorded is still refused at approval.
 *
 * TC INTERLOCK: the issued-TC check reads `student_transfers.tc_status` (the
 * authoritative marker) and is mirrored by StudentTransferService::issue(),
 * which refuses to issue a TC while a promotion is still pending. The two
 * workflows therefore cannot interleave into an inconsistent state; neither one
 * silently cancels or alters the other's records.
 *
 * Both phases are transactional and tenant-verified: every reference is
 * re-resolved through its CollegeScope (a foreign college's id 404s) and every
 * contextual rule (term belongs to the year, section belongs to the year and
 * program, enrollment belongs to the student) is re-checked server-side, so a
 * request cannot be approved into an inconsistent state even if the original
 * payload was tampered with between request and approval.
 *
 * Duplicate detection mirrors the enrollment tuple policy: one pending request
 * per student + target academic year + target program (section is not part of
 * the tuple), so a request that approval could never satisfy is never recorded.
 */
class PromoteStudent
{
    public const AUDITED = [
        'id', 'student_id', 'source_enrollment_id', 'source_academic_year_id', 'source_program_id',
        'source_section_id', 'target_academic_year_id', 'target_program_id', 'target_academic_term_id',
        'target_section_id', 'target_enrollment_id', 'effective_date', 'status', 'remarks',
        'approved_by', 'approved_at',
    ];

    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Record a pending promotion request. Nothing is enrolled yet.
     */
    public function request(array $data, int $collegeId, ?int $userId = null): StudentPromotion
    {
        return DB::transaction(function () use ($data, $collegeId, $userId): StudentPromotion {
            $student = $this->lockStudent($collegeId, (int) $data['student_id']);

            $source = $this->resolveEnrollment($collegeId, $student, (int) $data['source_enrollment_id']);

            // Same eligibility rule as approval, applied up front: a withdrawn
            // student or a withdrawn source enrollment can never be promoted,
            // so no paperwork is recorded for a decision approval must refuse.
            $this->assertPromotable($student, $source, $collegeId);

            $targetYear = AcademicYear::query()->find((int) $data['target_academic_year_id']);
            if (! $targetYear) {
                abort(404, 'Target academic year not found in this college context.');
            }

            if ((int) $targetYear->id === (int) $source->academic_year_id) {
                throw ValidationException::withMessages([
                    'target_academic_year_id' => 'The target academic year must be different from the source enrollment\'s academic year.',
                ]);
            }

            $targetProgramId = $data['target_program_id'] ?? null;
            if ($targetProgramId && ! Program::query()->find($targetProgramId)) {
                abort(404, 'Target program not found in this college context.');
            }

            $targetTermId = $this->resolveTerm($data['target_academic_term_id'] ?? null, (int) $targetYear->id);
            $targetSectionId = $this->resolveSection($data['target_section_id'] ?? null, (int) $targetYear->id, $targetProgramId);

            // One open request per student/target context. The key mirrors the
            // enrollment tuple exactly (student + academic year + program,
            // see StudentService::hasNonDeletedEnrollmentForTuple): section is
            // deliberately NOT part of it, so a request that approval could
            // never satisfy — a second pending request into the same year and
            // program but a different section — is refused here instead of
            // being recorded and then failing at approval.
            $pendingDuplicate = StudentPromotion::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->where('target_academic_year_id', $targetYear->id)
                ->where('target_program_id', $targetProgramId)
                ->where('status', 'pending')
                ->whereNull('deleted_at')
                ->exists();

            if ($pendingDuplicate) {
                throw ValidationException::withMessages([
                    'target_academic_year_id' => 'A pending promotion already exists for this student into the selected academic year and program.',
                ]);
            }

            // Reject up front what approval could never do.
            $this->assertNoTargetEnrollment($collegeId, $student->id, (int) $targetYear->id, $targetProgramId);

            $promotion = StudentPromotion::create([
                'college_id' => $collegeId,
                'student_id' => $student->id,
                'source_enrollment_id' => $source->id,
                'source_academic_year_id' => $source->academic_year_id,
                'source_program_id' => $source->program_id,
                'source_section_id' => $source->section_id,
                'target_academic_year_id' => $targetYear->id,
                'target_program_id' => $targetProgramId,
                'target_academic_term_id' => $targetTermId,
                'target_section_id' => $targetSectionId,
                'effective_date' => $data['effective_date'] ?? null,
                'status' => 'pending',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_promotion.requested', $promotion, [], $promotion->only(self::AUDITED));

            return $promotion;
        });
    }

    /**
     * Execute a pending promotion inside one transaction:
     * target enrollment created + source enrollment completed + decision stamped.
     *
     * If any step fails the whole transaction rolls back, so a student can never
     * end up with a target enrollment but a still-active source enrollment (or
     * the other way round).
     */
    public function approve(StudentPromotion $promotion, int $collegeId, ?int $userId = null): StudentPromotion
    {
        return DB::transaction(function () use ($promotion, $collegeId, $userId): StudentPromotion {
            if ((int) $promotion->college_id !== (int) $collegeId) {
                abort(404, 'Promotion not found in this college context.');
            }

            // Number generation and other student lifecycle workflows serialize
            // on College. Acquire it before promotion/student/enrollment rows.
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            // The controller's model may have been loaded before another request
            // approved/cancelled this promotion. Serialize decisions on the
            // promotion row, then make the state decision from the locked row.
            $promotion = $this->lockPromotion($promotion, $collegeId);

            if (! $promotion->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending promotion can be approved. Current status: '.$promotion->status.'.',
                ]);
            }

            // Re-resolve both sides of the source relationship after acquiring
            // the student lock. College scope, explicit tenant ownership, and
            // student ownership must all still agree with the locked promotion.
            $student = $this->lockStudent($collegeId, (int) $promotion->student_id);

            $source = $promotion->source_enrollment_id
                ? StudentEnrollment::query()
                    ->where('college_id', $collegeId)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->find($promotion->source_enrollment_id)
                : null;

            if (! $source) {
                throw ValidationException::withMessages([
                    'source_enrollment_id' => 'The source enrollment is no longer available for this student, so the promotion cannot be approved.',
                ]);
            }

            // These statuses are re-read only after both rows are locked. TC
            // issuance withdraws both records; a pending promotion must not
            // create a new active enrollment for either withdrawn record.
            // This is the SAME guard request() applies, so a promotion that
            // became ineligible after the request was recorded is refused here
            // with the identical message.
            $this->assertPromotable($student, $source, $collegeId);

            // Re-resolve every target reference: a foreign or archived id 404s.
            $targetYear = AcademicYear::query()->find((int) $promotion->target_academic_year_id);
            if (! $targetYear) {
                abort(404, 'Target academic year not found in this college context.');
            }

            if ($promotion->target_program_id && ! Program::query()->find($promotion->target_program_id)) {
                abort(404, 'Target program not found in this college context.');
            }

            $targetTermId = $this->resolveTerm($promotion->target_academic_term_id, (int) $targetYear->id);
            $targetSectionId = $this->resolveSection($promotion->target_section_id, (int) $targetYear->id, $promotion->target_program_id);

            // 1. Create the target enrollment (server-generated number, audited).
            // StudentService remains the single duplicate-tuple authority; use
            // the promotion-specific field/message if a tuple appeared after
            // request time.
            $target = $this->students->createEnrollment([
                'student_id' => $student->id,
                'academic_year_id' => $targetYear->id,
                'program_id' => $promotion->target_program_id,
                'section_id' => $targetSectionId,
                'enrollment_date' => $promotion->effective_date?->toDateString() ?? now()->toDateString(),
                'status' => 'active',
                'remarks' => 'Created by promotion from enrollment '.$source->enrollment_number.'.',
            ], $collegeId, $targetYear->code,
                audit: true,
                duplicateErrorField: 'target_academic_year_id',
                duplicateErrorMessage: 'The student already has an enrollment for the target academic year and program.',
                collegeLockHeld: true,
            );

            // 2. Preserve history: the source enrollment stays; only an ACTIVE
            //    source is moved to completed. A `completed` source is already
            //    in its terminal state, and a `cancelled` source must NOT be
            //    silently rewritten to `completed` — that would replace the
            //    recorded reason the enrollment was cancelled with a claim that
            //    the student finished it. `withdrawn` cannot reach here (the
            //    eligibility guard above rejects it).
            if ($source->status === 'active') {
                $this->students->updateEnrollment($source, ['status' => 'completed'], $collegeId);
            }

            // 3. Stamp the decision.
            $old = $promotion->only(self::AUDITED);

            $promotion->update([
                'target_enrollment_id' => $target->id,
                'target_academic_term_id' => $targetTermId,
                'target_section_id' => $targetSectionId,
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_promotion.approved', $promotion, $old, $promotion->only(self::AUDITED));

            // 4. Keep the progression ledger consistent with the decision.
            //    Same transaction, so a promotion and its ledger outcome can
            //    never be observed apart — and if the ledger write fails the
            //    whole approval rolls back with it.
            $this->recordPromotionOutcomeOnSourceLedger($promotion, $collegeId, $userId);

            return $promotion->refresh()->load(['sourceEnrollment', 'targetEnrollment', 'targetAcademicYear', 'student']);
        });
    }

    /**
     * Withdraw a promotion that has not been approved yet.
     */
    public function cancel(StudentPromotion $promotion, int $collegeId, ?int $userId = null): StudentPromotion
    {
        return DB::transaction(function () use ($promotion, $collegeId, $userId): StudentPromotion {
            // Use the locked database state, not the caller's potentially stale
            // model, so an approval that committed first cannot be overwritten.
            $promotion = $this->lockPromotion($promotion, $collegeId);

            if (! $promotion->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending promotion can be cancelled. Current status: '.$promotion->status.'.',
                ]);
            }

            $old = $promotion->only(self::AUDITED);

            $promotion->update([
                'status' => 'cancelled',
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_promotion.cancelled', $promotion, $old, $promotion->only(self::AUDITED));

            // The progression ledger is deliberately NOT touched here.
            // Cancellation is only reachable from `pending`, and `promoted` is
            // written only by approve() — which is terminal, so no promotion is
            // ever both approved and cancelled. A cancelling promotion
            // therefore never wrote the ledger, and there is nothing of its own
            // to reverse. Resetting `promoted` here could only destroy a value
            // an operator typed by hand or another approved promotion set.
            //
            return $promotion;
        });
    }

    /**
     * Re-read a promotion under its tenant scope and serialize its terminal
     * decision. The passed model supplies its key and an early tenant check;
     * its persisted state is re-read because it may be stale when called.
     */
    private function lockPromotion(StudentPromotion $promotion, int $collegeId): StudentPromotion
    {
        if ((int) $promotion->college_id !== (int) $collegeId) {
            abort(404, 'Promotion not found in this college context.');
        }

        $locked = StudentPromotion::query()
            ->where('college_id', $collegeId)
            ->lockForUpdate()
            ->find($promotion->getKey());

        if (! $locked) {
            abort(404, 'Promotion not found in this college context.');
        }

        return $locked;
    }

    private function lockStudent(int $collegeId, int $studentId): Student
    {
        $student = Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereKey($studentId)
            ->lockForUpdate()
            ->first();

        if (! $student) {
            abort(404, 'Student not found in this college context.');
        }

        return $student;
    }

    private function resolveEnrollment(int $collegeId, Student $student, int $enrollmentId): StudentEnrollment
    {
        // Tenant-scoped by CollegeScope and must belong to this student.
        $enrollment = StudentEnrollment::query()
            ->where('student_id', $student->id)
            ->find($enrollmentId);

        if (! $enrollment) {
            abort(404, 'Source enrollment not found for this student in this college context.');
        }

        return $enrollment;
    }

    /**
     * Shared source/student eligibility guard, applied identically by
     * request() and by approve().
     *
     * Three refusals, checked in this order:
     *
     * 1. AN ISSUED TRANSFER CERTIFICATE. `student_transfers.tc_status` is the
     *    authoritative marker (`StudentTransfer::isTcIssued()`), not the
     *    `certificates` table: Certificate has its own requested/generated/
     *    issued lifecycle and CertificateType::BUILT_INS covers seven kinds of
     *    certificate of which only `transfer` is a TC, so a bonafide or
     *    character certificate must never trigger this guard. An issued TC is
     *    terminal — StudentTransferService refuses to cancel or delete one —
     *    so it is checked BEFORE the status guards: the student has formally
     *    left, and that fact survives even when the withdrawn status does not
     *    (CertificateWorkflow's legacy-TC "adoption" path records an issued TC
     *    without replaying the withdrawal, and a student's status can be
     *    edited back while tc_status stays `issued`).
     * 2. a withdrawn Student, and 3. a withdrawn source enrollment. TC
     *    issuance withdraws both, and neither may ever be promoted into a new
     *    active enrollment.
     *
     * Both callers read these only from rows they have already locked (Student,
     * then the source enrollment), so the decision is made against committed
     * state. The transfer read needs no lock of its own: issuing a TC takes the
     * College lock — which approve() already holds — and then the same Student
     * lock request() holds, so a TC cannot be issued for this student while
     * this guard runs. Because the guard is a single method, a request recorded
     * before a TC was issued is refused at approval with the identical message.
     *
     * Only `withdrawn` is rejected among enrollment statuses. Whether a
     * `completed` or `cancelled` source may be promoted from is a separate
     * business decision and is deliberately not encoded here; what is
     * guaranteed is that such a source is never silently rewritten (see
     * approve()).
     */
    private function assertPromotable(Student $student, StudentEnrollment $source, int $collegeId): void
    {
        $hasIssuedTc = StudentTransfer::query()
            ->where('college_id', $collegeId)
            ->where('student_id', $student->id)
            ->where('tc_status', 'issued')
            ->exists();

        if ($hasIssuedTc) {
            throw ValidationException::withMessages([
                'student_id' => 'A transfer certificate has already been issued for this student, so a promotion cannot be recorded.',
            ]);
        }

        if ($student->status === 'withdrawn') {
            throw ValidationException::withMessages([
                'student_id' => 'A withdrawn student cannot receive a new active enrollment through promotion.',
            ]);
        }

        if ($source->status === 'withdrawn') {
            throw ValidationException::withMessages([
                'source_enrollment_id' => 'A withdrawn source enrollment cannot be promoted into a new active enrollment.',
            ]);
        }
    }

    /**
     * A term is only valid when it belongs to the given academic year.
     */
    private function resolveTerm(?int $termId, int $yearId): ?int
    {
        if (! $termId) {
            return null;
        }

        $term = AcademicTerm::query()->where('academic_year_id', $yearId)->find($termId);

        if (! $term) {
            throw ValidationException::withMessages([
                'target_academic_term_id' => 'The selected academic term is not valid for the target academic year in this college.',
            ]);
        }

        return (int) $term->id;
    }

    /**
     * A section is only valid when it belongs to the target academic year and
     * to the chosen program.
     *
     * `sections.program_id` is NOT NULL (see
     * 2026_09_19_000002_create_sections_table), so every Section belongs to
     * exactly one Program. When no target program is chosen there is therefore
     * no section that could legitimately be attached, and one is refused
     * instead of being silently recorded against a program-less enrollment —
     * the same rule StudentService applies on enrollment create/update.
     */
    private function resolveSection(?int $sectionId, int $yearId, ?int $programId): ?int
    {
        if (! $sectionId) {
            return null;
        }

        $query = Section::query()->where('academic_year_id', $yearId);
        if ($programId) {
            $query->where('program_id', $programId);
        }

        $section = $query->find($sectionId);

        if (! $section) {
            throw ValidationException::withMessages([
                'target_section_id' => 'The selected section is not valid for the target academic year and program in this college.',
            ]);
        }

        if (! $programId && $section->program_id !== null) {
            throw ValidationException::withMessages([
                'target_section_id' => StudentService::SECTION_REQUIRES_PROGRAM,
            ]);
        }

        return (int) $section->id;
    }

    /**
     * Duplicate target enrollment protection, checked before any write so the
     * failure message names the target rather than the enrollment table.
     */
    private function assertNoTargetEnrollment(int $collegeId, int $studentId, int $yearId, ?int $programId): void
    {
        if ($this->students->hasNonDeletedEnrollmentForTuple($collegeId, $studentId, $yearId, $programId)) {
            throw ValidationException::withMessages([
                'target_academic_year_id' => 'The student already has an enrollment for the target academic year and program.',
            ]);
        }
    }

    /**
     * Keep the progression ledger (`student_academic_records`) consistent with
     * an approved promotion, instead of leaving the two to disagree.
     *
     * WHICH PERIOD: the migration documents `promotion_status` as the
     * "Progression outcome for the period", one row per student + academic
     * year + term, and every screen renders it as a column beside that row's
     * period label. So the outcome belongs to the SOURCE period — the one the
     * student is promoted OUT of. The target period is one the student is only
     * entering; its outcome is not known yet and must not be pre-filled with
     * `promoted`, which would also collide with the day they are promoted out
     * of it.
     *
     * HOW NARROW: the ledger carries no back-pointer to the promotion that
     * influenced it, and an operator sets the same column by hand through
     * StudentAcademicRecordController. So this writes as little as it can
     * while still being useful:
     *
     * - it only UPDATES an existing row. It never creates one: a promotion
     *   cannot derive `academic_status` or `completion_status`, so a new row
     *   would have to invent them, and inventing a period record is worse than
     *   writing none.
     * - it only overwrites the untouched default `not_applicable`. A value an
     *   operator chose (`retained`, `transferred`, …) is preserved, because a
     *   promotion is not evidence against it.
     * - if the student has more than one row for the source year — one per
     *   term — the correct row cannot usually be identified, because the
     *   source enrollment carries no term (`student_enrollments` has no
     *   `academic_term_id`). The one deterministic exception is a row that
     *   points back at the SOURCE enrollment itself
     *   (`student_academic_records.enrollment_id`): when exactly one row
     *   does so, that row is the source period's record and is written.
     *   When no row (or more than one row) is so linked, writing to all of
     *   them, or guessing one, would corrupt a term-based ledger, so
     *   nothing is written — but the skip is audited
     *   (`student_promotion.ledger_write_skipped`) so the divergence is
     *   visible to operators instead of silent (audit finding AUD-005).
     *
     * There is deliberately no reverse write: cancellation is only reachable
     * from `pending` and `promoted` is only written here, so a cancelled
     * promotion never wrote the ledger. See cancel().
     *
     * Locking: rows are read under `lockForUpdate()` so the default-value check
     * cannot be lost to a concurrent operator edit. Promotion is always locked
     * before these rows (College → Promotion → Student → source enrollment),
     * and StudentAcademicRecordService likewise locks Student before the
     * record, so no lock order is inverted and no new lock is introduced ahead
     * of an existing one.
     *
     * Runs inside the caller's transaction: a failure here rolls the whole
     * decision back, so a promotion and its ledger outcome can never be
     * observed apart.
     */
    private function recordPromotionOutcomeOnSourceLedger(
        StudentPromotion $promotion,
        int $collegeId,
        ?int $userId,
    ): void {
        $yearId = $promotion->source_academic_year_id;

        // A legacy promotion without a snapshot of the source year gives no
        // period to attribute the outcome to.
        if (! $yearId) {
            return;
        }

        $records = StudentAcademicRecord::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_id', $promotion->student_id)
            ->where('academic_year_id', $yearId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get();

        // Nothing recorded (0): leave it.
        if ($records->isEmpty()) {
            return;
        }

        if ($records->count() === 1) {
            $record = $records->first();
        } else {
            // Term-based ledger (2+ rows for the source year): the only row
            // that can be identified without guessing a term is the one
            // linked to the source enrollment being promoted out of.
            $linked = $records->filter(
                fn (StudentAcademicRecord $candidate): bool => $candidate->enrollment_id !== null
                    && (int) $candidate->enrollment_id === (int) $promotion->source_enrollment_id
            );

            if ($linked->count() !== 1) {
                // Ambiguous: write nothing, but do not skip silently —
                // audit the skip so the ledger/promotion divergence is
                // visible and reconcilable by an operator.
                if ($records->contains(
                    fn (StudentAcademicRecord $candidate): bool => $candidate->promotion_status === 'not_applicable'
                )) {
                    $this->audit->record('student_promotion.ledger_write_skipped', $promotion, [], [
                        'reason' => 'multiple_source_year_records_without_unique_source_enrollment_link',
                        'source_academic_year_id' => $yearId,
                        'record_count' => $records->count(),
                    ]);
                }

                return;
            }

            $record = $linked->first();
        }

        // Never overwrite an outcome an operator chose.
        if ($record->promotion_status !== 'not_applicable') {
            return;
        }

        $old = $record->only(StudentAcademicRecordService::AUDITED);

        $record->update([
            'promotion_status' => 'promoted',
            'updated_by' => $userId,
        ]);

        $this->audit->record(
            'student_academic_record.updated',
            $record,
            $old,
            $record->only(StudentAcademicRecordService::AUDITED),
        );
    }
}
