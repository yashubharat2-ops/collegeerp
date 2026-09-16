<?php

namespace App\Domain\Admission\Actions;

use App\Domain\Admission\Services\DuplicateApplicantDetector;
use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionEnquiry;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Creates enquiry + applicant together in ONE transaction.
 *
 * Workflow:
 * - Resolve college from TenantContext (not browser)
 * - Validate applicant_id belongs to same college (if provided)
 * - If applicant_id null, create minimal applicant from applicant_* fields
 * - Generate enquiry number via GenerateEnquiryNumber (safe locking)
 * - Create enquiry
 * - Audit both
 * - Commit, or rollback on any failure (no orphan)
 */
class CreateEnquiryWithApplicant
{
    public function __construct(
        private readonly GenerateEnquiryNumber $numberGenerator,
        private readonly DuplicateApplicantDetector $duplicateDetector,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param array $data Validated data from StoreAdmissionEnquiryRequest
     * @param int $collegeId Resolved from TenantContext
     * @return AdmissionEnquiry Created enquiry with applicant relation
     */
    public function execute(array $data, int $collegeId): AdmissionEnquiry
    {
        return DB::transaction(function () use ($data, $collegeId): AdmissionEnquiry {
            // Resolve applicant
            $applicant = null;

            if (! empty($data['applicant_id'])) {
                // Existing applicant reuse — already validated same college via FormRequest
                $applicant = AdmissionApplicant::query()->findOrFail($data['applicant_id']);
            } else {
                // Create minimal applicant from applicant_* fields
                $applicantData = [
                    'college_id' => $collegeId,
                    'first_name' => $data['applicant_first_name'] ?? 'Unknown',
                    'middle_name' => $data['applicant_middle_name'] ?? null,
                    'last_name' => $data['applicant_last_name'] ?? null,
                    'email' => $data['applicant_email'] ?? null,
                    'phone' => $data['applicant_phone'] ?? null,
                    'alternate_phone' => $data['applicant_alternate_phone'] ?? null,
                    'gender' => $data['applicant_gender'] ?? null,
                    'date_of_birth' => $data['applicant_date_of_birth'] ?? null,
                    'address' => $data['applicant_address'] ?? null,
                    'status' => 'active',
                ];

                // Optional duplicate check for logging/audit (does not block creation)
                // UI should have warned, but we still allow creation if user explicitly wants new
                $applicant = AdmissionApplicant::create($applicantData);
                $this->audit->record('admission_applicant.created', $applicant, [], $applicant->only([
                    'id', 'first_name', 'last_name', 'email', 'phone', 'status'
                ]));
            }

            // Resolve academic year code for number generation if available
            $academicYearCode = null;
            if (! empty($data['academic_year_id'])) {
                $year = AcademicYear::withoutGlobalScopes()->find($data['academic_year_id']);
                $academicYearCode = $year?->code;
            }

            $enquiryNumber = $this->numberGenerator->execute($collegeId, $data['academic_year_id'] ?? null, $academicYearCode);

            $enquiryData = [
                'college_id' => $collegeId,
                'applicant_id' => $applicant->id,
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'program_id' => $data['program_id'] ?? null,
                'enquiry_number' => $enquiryNumber,
                'source' => $data['source'] ?? null,
                'status' => $data['status'] ?? 'new',
                'remarks' => $data['remarks'] ?? null,
                'enquired_at' => $data['enquired_at'] ?? now(),
                'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            ];

            $enquiry = AdmissionEnquiry::create($enquiryData);
            $this->audit->record('admission_enquiry.created', $enquiry, [], $enquiry->only([
                'id', 'enquiry_number', 'applicant_id', 'academic_year_id', 'program_id', 'status', 'source'
            ]));

            return $enquiry->load(['applicant', 'academicYear', 'program']);
        });
    }

    /**
     * Helper for UI to get possible duplicates (same college only)
     */
    public function findDuplicates(int $collegeId, ?string $phone, ?string $email)
    {
        return $this->duplicateDetector->detect($collegeId, $phone, $email);
    }
}
