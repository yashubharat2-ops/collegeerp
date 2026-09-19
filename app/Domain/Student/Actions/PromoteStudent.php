<?php

namespace App\Domain\Student\Actions;

use App\Domain\Student\Services\StudentService;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
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
 * - the source enrollment is only flipped to `completed` — its row, enrollment
 *   number and audit trail are preserved,
 * - nothing is deleted, and the decision itself is stored on StudentPromotion
 *   so it stays auditable after the fact.
 *
 * NO progression rule is encoded here. Which academic year, program, term and
 * section a student moves into is entirely the operator's choice, so the module
 * works for annual, semester, lateral-entry and re-admission cases alike.
 *
 * Both phases are transactional and tenant-verified: every reference is
 * re-resolved through its CollegeScope (a foreign college's id 404s) and every
 * contextual rule (term belongs to the year, section belongs to the year and
 * program, enrollment belongs to the student) is re-checked server-side, so a
 * request cannot be approved into an inconsistent state even if the original
 * payload was tampered with between request and approval.
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

            // One open request per student/target context.
            $pendingDuplicate = StudentPromotion::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->where('target_academic_year_id', $targetYear->id)
                ->where('target_program_id', $targetProgramId)
                ->where('target_section_id', $targetSectionId)
                ->where('status', 'pending')
                ->whereNull('deleted_at')
                ->exists();

            if ($pendingDuplicate) {
                throw ValidationException::withMessages([
                    'target_academic_year_id' => 'A pending promotion already exists for this student into the selected academic year, program and section.',
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

            if (! $promotion->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending promotion can be approved. Current status: '.$promotion->status.'.',
                ]);
            }

            $student = $this->lockStudent($collegeId, (int) $promotion->student_id);

            // Re-resolve the source enrollment tenant-scoped: it must still exist.
            $source = $promotion->source_enrollment_id
                ? StudentEnrollment::query()->where('student_id', $student->id)->find($promotion->source_enrollment_id)
                : null;

            if (! $source) {
                throw ValidationException::withMessages([
                    'source_enrollment_id' => 'The source enrollment is no longer available for this student, so the promotion cannot be approved.',
                ]);
            }

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

            $this->assertNoTargetEnrollment($collegeId, $student->id, (int) $targetYear->id, $promotion->target_program_id);

            // 1. Create the target enrollment (server-generated number, audited).
            $target = $this->students->createEnrollment([
                'student_id' => $student->id,
                'academic_year_id' => $targetYear->id,
                'program_id' => $promotion->target_program_id,
                'section_id' => $targetSectionId,
                'enrollment_date' => $promotion->effective_date?->toDateString() ?? now()->toDateString(),
                'status' => 'active',
                'remarks' => 'Created by promotion from enrollment '.$source->enrollment_number.'.',
            ], $collegeId, $targetYear->code, audit: true);

            // 2. Preserve history: the source enrollment stays, status completed.
            if ($source->status !== 'completed') {
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

            return $promotion->refresh()->load(['sourceEnrollment', 'targetEnrollment', 'targetAcademicYear', 'student']);
        });
    }

    /**
     * Withdraw a promotion that has not been approved yet.
     */
    public function cancel(StudentPromotion $promotion, int $collegeId, ?int $userId = null): StudentPromotion
    {
        return DB::transaction(function () use ($promotion, $collegeId, $userId): StudentPromotion {
            if ((int) $promotion->college_id !== (int) $collegeId) {
                abort(404, 'Promotion not found in this college context.');
            }

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

            return $promotion;
        });
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
     * (when a program is chosen) to that program.
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

        return (int) $section->id;
    }

    /**
     * Duplicate target enrollment protection, checked before any write so the
     * failure message names the target rather than the enrollment table.
     */
    private function assertNoTargetEnrollment(int $collegeId, int $studentId, int $yearId, ?int $programId): void
    {
        $exists = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $yearId)
            ->where('program_id', $programId)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'target_academic_year_id' => 'The student already has an enrollment for the target academic year and program.',
            ]);
        }
    }
}
