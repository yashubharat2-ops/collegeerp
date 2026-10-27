<?php

namespace App\Domain\Student\Services;

use App\Models\StudentEnrollment;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * The one entry point for creating an enrollment FROM THE ENROLLMENTS SCREEN,
 * including its optional approval gate.
 *
 * Why a separate service: creating the row and (optionally) raising the approval
 * request must be one atomic act. A row created as `pending_approval` with no
 * request behind it would be undecidable forever, and a request raised for a row
 * that was rolled back would point at nothing.
 *
 * The gate itself is the existing approval framework, not a parallel mechanism:
 * `ApprovalService::isApprovalRequired()` decides (the college's Approval
 * Settings, defaulting to off, like every module except admission / student
 * registration), and `submitRequest()` validates the module/action allowlist,
 * refuses a duplicate pending request and records the audit entry.
 *
 * What is NOT gated: an enrollment created BY a registration approval, or by
 * promotion / admission conversion. Those flows already went through a human
 * decision for the academic placement; asking again would be a second approval
 * of the same act and would strand the student between two queues.
 */
class EnrollmentApprovalService
{
    public const MODULE = 'student';
    public const ACTION = 'enrollment_approval';

    public function __construct(
        private readonly StudentService $students,
        private readonly ApprovalService $approvals,
    ) {}

    /** Whether this college requires a human decision before an enrollment is active. */
    public function isRequired(int $collegeId): bool
    {
        return $this->approvals->isApprovalRequired($collegeId, self::MODULE, self::ACTION);
    }

    /**
     * Create an enrollment and, when the college requires it, queue it for
     * approval instead of making it operational.
     *
     * @param  array<string, mixed>  $data  validated create payload
     * @return array{enrollment: StudentEnrollment, approval_request: ?\App\Models\ApprovalRequest, pending: bool}
     */
    public function create(array $data, int $collegeId, int $actorId): array
    {
        $gated = $this->isRequired($collegeId);

        // Only the "make it operational" intent is gated. Recording a completed
        // or cancelled historical enrollment stays a direct clerical action.
        $requested = $data['status'] ?? StudentEnrollment::STATUS_ACTIVE;

        if ($gated && $requested === StudentEnrollment::STATUS_ACTIVE) {
            $data['status'] = StudentEnrollment::STATUS_PENDING_APPROVAL;
        }

        return DB::transaction(function () use ($data, $collegeId, $actorId): array {
            $enrollment = $this->students->createEnrollment($data, $collegeId, audit: true);

            if (! $enrollment->isPendingApproval()) {
                return ['enrollment' => $enrollment, 'approval_request' => null, 'pending' => false];
            }

            $request = $this->approvals->submitRequest([
                'module' => self::MODULE,
                'action' => self::ACTION,
                'target_type' => self::MODULE.'.'.self::ACTION,
                'target_id' => $enrollment->id,
                'title' => 'Enrollment '.$enrollment->enrollment_number.' ('.$enrollment->student?->student_number.')',
                'description' => 'Enrollment for academic year '.($enrollment->academicYear?->name ?? $enrollment->academic_year_id)
                    .($enrollment->program ? ' / '.$enrollment->program->name : '')
                    .($enrollment->section ? ' / '.$enrollment->section->name : '')
                    .'. Approving makes it the student\'s active enrollment.',
                'payload' => [
                    'enrollment_number' => $enrollment->enrollment_number,
                    'roll_number' => $enrollment->roll_number,
                    'student_id' => $enrollment->student_id,
                    'academic_year_id' => $enrollment->academic_year_id,
                    'program_id' => $enrollment->program_id,
                    'section_id' => $enrollment->section_id,
                    'semester' => $enrollment->semester,
                ],
                'previous_data' => ['status' => StudentEnrollment::STATUS_PENDING_APPROVAL],
            ], $collegeId, $actorId);

            return ['enrollment' => $enrollment->fresh(), 'approval_request' => $request, 'pending' => true];
        });
    }
}
