<?php

namespace App\Domain\Admission\Actions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Creates an admission application transactionally.
 *
 * Workflow:
 * - Resolve college from TenantContext (never the browser)
 * - Re-resolve applicant / academic year / program / enquiry via tenant-scoped
 *   lookups, so a forged id from another college 404s even if FormRequest
 *   validation were bypassed
 * - Generate the application number via GenerateApplicationNumber (safe locking)
 * - Set submitted_at server-side: now() when created in a non-draft status,
 *   null for drafts. Browser timestamps are never trusted.
 * - Audit the creation
 * - Commit, or rollback on any failure (no orphan / partial state)
 *
 * Status set for this stage: draft, submitted, under_review, approved,
 * rejected, cancelled. Kept deliberately small; future Document Verification,
 * Merit/Selection and Admission Confirmation modules extend it.
 */
class CreateApplication
{
    public function __construct(
        private readonly GenerateApplicationNumber $numberGenerator,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param array $data Validated data from StoreAdmissionApplicationRequest
     * @param int $collegeId Resolved from TenantContext
     */
    public function execute(array $data, int $collegeId): AdmissionApplication
    {
        return DB::transaction(function () use ($data, $collegeId): AdmissionApplication {
            // Tenant-scoped re-resolution: cross-college ids fail here with 404.
            $applicant = AdmissionApplicant::query()->findOrFail($data['applicant_id']);
            $year = AcademicYear::query()->findOrFail($data['academic_year_id']);
            $program = Program::query()->findOrFail($data['program_id']);
            $enquiry = ! empty($data['enquiry_id'])
                ? AdmissionEnquiry::query()->findOrFail($data['enquiry_id'])
                : null;

            $applicationNumber = $this->numberGenerator->execute($collegeId, $year->id, $year->code);

            $status = $data['status'] ?? 'draft';

            $application = AdmissionApplication::create([
                'college_id' => $collegeId,
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'enquiry_id' => $enquiry?->id,
                'application_number' => $applicationNumber,
                'status' => $status,
                'submitted_at' => $status !== 'draft' ? now() : null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->audit->record('admission_application.created', $application, [], $application->only([
                'id', 'application_number', 'applicant_id', 'academic_year_id', 'program_id', 'enquiry_id', 'status',
            ]));

            return $application->load(['applicant', 'academicYear', 'program', 'enquiry']);
        });
    }
}
