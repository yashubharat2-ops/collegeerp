<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Support\Aadhaar;
use App\Models\AcademicYear;
use App\Models\StudentRegistrationDraftDocument;
use App\Models\ApprovalRequest;
use App\Models\Student;
use App\Services\Approval\ApprovalService;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One write path for unified student registration.
 *
 * The rich Student form, the bulk importer and any future public entry point
 * all call this service. It creates a pending Student (never an approved one),
 * stores optional registration documents in the existing student_documents
 * store, and raises exactly one central ApprovalRequest. Approval side effects
 * live in ApprovalService, so no parallel approval workflow is introduced.
 */
final class StudentRegistrationService
{
    public function __construct(
        private readonly StudentService $students,
        private readonly StudentDocumentService $documents,
        private readonly ApprovalService $approvals,
        private readonly AuditLogService $audit,
        private readonly StudentQualificationService $qualifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated Student profile payload
     * @param  array<int, UploadedFile>  $files  optional registration documents uploaded with the POST
     * @param  array<int, array<string, mixed>>  $qualifications  previous qualifications (wizard step 3)
     * @param  array<int, StudentRegistrationDraftDocument>  $stagedDocuments  files uploaded while the wizard had no student
     * @param  bool  $replaceQualifications  true when the caller OWNS the qualification list (the wizard does,
     *                                       so an emptied step really means "these are all of them"). Callers
     *                                       that know nothing about qualifications (the single-POST form, the
     *                                       importer) leave this false, so they never delete records they did
     *                                       not supply.
     * @return array{student: Student, approval_request: ApprovalRequest, created: bool}
     */
    public function register(
        array $data,
        array $files,
        int $collegeId,
        int $actorId,
        array $qualifications = [],
        array $stagedDocuments = [],
        bool $replaceQualifications = false,
    ): array {
        if (! $this->approvals->isApprovalRequired($collegeId, 'student', 'registration_approval')) {
            throw ValidationException::withMessages([
                'approval' => 'Student registration approval is disabled for this college. Re-enable it in Approval Settings before submitting registrations.',
            ]);
        }

        return DB::transaction(function () use ($data, $files, $collegeId, $actorId, $qualifications, $stagedDocuments, $replaceQualifications): array {
            $fingerprint = $this->fingerprint($data);

            // A repeated POST is matched by the server-side identity fingerprint
            // stored on the ApprovalRequest, not by a client token. A live
            // pending request is returned unchanged; a cancelled/rejected
            // request reuses its Student and raises one new request.
            $priorRequests = ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('module', 'student')
                ->where('action', 'registration_approval')
                ->get();

            $reusableStudent = null;
            foreach ($priorRequests as $prior) {
                if (($prior->payload['registration_fingerprint'] ?? null) !== $fingerprint) {
                    continue;
                }

                $candidate = Student::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->whereKey($prior->target_id)
                    ->first();

                if (! $candidate || $candidate->isRegistrationApproved()) {
                    continue;
                }

                if ($prior->status === ApprovalRequest::STATUS_PENDING) {
                    return [
                        'student' => $candidate,
                        'approval_request' => $prior,
                        'created' => false,
                    ];
                }

                $reusableStudent = $candidate;
            }

            if ($reusableStudent) {
                $old = $reusableStudent->only(['status', 'registration_status']);

                // A resubmission may carry corrected data: the profile is
                // refreshed from the newly validated payload and the previous
                // qualification list is replaced by the submitted rows. Both
                // writes happen inside this transaction, so a failure leaves the
                // rejected record exactly as it was.
                $reusableStudent->update($this->studentAttributes($data, columnsOnly: true) + [
                    'status' => 'inactive',
                    'registration_status' => Student::REGISTRATION_PENDING,
                ]);

                if ($replaceQualifications) {
                    $this->qualifications->syncForStudent($reusableStudent, $qualifications, $collegeId, $actorId);
                }
                $this->adoptDocuments($reusableStudent, $stagedDocuments, $collegeId, $actorId);

                $approvalRequest = $this->submitApprovalRequest(
                    $reusableStudent,
                    $fingerprint,
                    $data,
                    $collegeId,
                    $actorId,
                );
                $this->audit->record('student.registration_resubmitted', $reusableStudent, $old, [
                    'status' => $reusableStudent->status,
                    'registration_status' => $reusableStudent->registration_status,
                    'approval_request_id' => $approvalRequest->id,
                ]);

                return [
                    'student' => $reusableStudent,
                    'approval_request' => $approvalRequest,
                    'created' => false,
                ];
            }

            $academicYearId = $data['academic_year_id'] ?? null;
            $year = $academicYearId ? AcademicYear::query()->find($academicYearId) : null;
            if ($academicYearId && ! $year) {
                throw ValidationException::withMessages([
                    'academic_year_id' => 'Choose an academic year that belongs to this college.',
                ]);
            }

            $studentData = Arr::except($data, [
                'academic_year_id', 'academic_term_id', 'program_id', 'section_id', 'semester',
                'registration_document_type_id', 'registration_documents', 'qualifications',
            ]);
            // Only real, fillable student columns are written: wizard-only keys
            // and any client-supplied server-owned column are dropped here.
            $studentData = $this->studentAttributes($studentData);
            $studentData['status'] = 'inactive';
            $studentData['registration_status'] = Student::REGISTRATION_PENDING;
            $studentData['registration_submitted_at'] = now();

            $student = $this->students->createStudent(
                $studentData,
                $collegeId,
                $studentData['admission_date'] ?? null,
                $year?->code,
                audit: false,
            );

            if ($replaceQualifications || $qualifications !== []) {
                $this->qualifications->syncForStudent($student, $qualifications, $collegeId, $actorId);
            }
            $this->adoptDocuments($student, $stagedDocuments, $collegeId, $actorId);

            foreach ($files as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

                $this->documents->store([
                    'student_id' => $student->id,
                    'document_type_id' => $data['registration_document_type_id'] ?? null,
                    'title' => $file->getClientOriginalName(),
                    'remarks' => 'Uploaded with registration submission.',
                ], $file, $collegeId, $actorId);
            }

            $approvalRequest = $this->submitApprovalRequest(
                $student,
                $fingerprint,
                array_merge($data, ['admission_date' => $studentData['admission_date'] ?? now()->toDateString()]),
                $collegeId,
                $actorId,
            );

            $this->audit->record('student.registration_submitted', $student, [], [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'status' => $student->status,
                'registration_status' => $student->registration_status,
                'approval_request_id' => $approvalRequest->id,
                'submitted_by' => $actorId,
            ]);

            return ['student' => $student, 'approval_request' => $approvalRequest, 'created' => true];
        });
    }

    private function submitApprovalRequest(
        Student $student,
        string $fingerprint,
        array $data,
        int $collegeId,
        int $actorId,
    ): ApprovalRequest {
        return $this->approvals->submitRequest([
            'module' => 'student',
            'action' => 'registration_approval',
            'target_type' => 'student.registration_approval',
            'target_id' => $student->id,
            'title' => 'Student registration: '.$student->student_number,
            'description' => 'Registration submitted by '.$actorId.' for '.$student->first_name.' '.$student->last_name.'.',
            'payload' => [
                'registration_fingerprint' => $fingerprint,
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'] ?? null,
                // The configured semester / year of study travels with the
                // request so the approver sees the real academic placement and
                // the initial enrollment is created with the same reference.
                'academic_term_id' => $data['academic_term_id'] ?? null,
                'program_id' => $data['program_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'semester' => $data['semester'] ?? null,
                'admission_date' => $data['admission_date'] ?? now()->toDateString(),
                // Possible-duplicate hints (same name + a corroborating detail).
                // Never a block — the approver sees them and decides.
                'duplicate_warnings' => $data['duplicate_warnings'] ?? [],
                // What the applicant DECLARED on step 3 (readable id + name),
                // kept next to the files that were adopted into the Documents
                // module. A declaration is not a verification: the files stay
                // `pending` until a person verifies them.
                'declared_documents' => $data['declared_documents'] ?? [],
            ],
            'previous_data' => [
                'status' => 'inactive',
                'registration_status' => Student::REGISTRATION_PENDING,
            ],
            'request_reason' => $data['remarks'] ?? null,
        ], $collegeId, $actorId);
    }

    /**
     * Adopt the files staged while the wizard had no student.
     *
     * The blob is NOT copied: the existing private-disk path is attached to the
     * Documents module, so the applicant's upload becomes a normal, verifiable
     * student document with `verification_status = pending`. The staging row is
     * removed afterwards, leaving no duplicate reference to the same file.
     *
     * @param  array<int, StudentRegistrationDraftDocument>  $documents
     */
    private function adoptDocuments(Student $student, array $documents, int $collegeId, int $actorId): void
    {
        foreach ($documents as $document) {
            if (! $document instanceof StudentRegistrationDraftDocument) {
                continue;
            }

            $this->documents->adoptStoredFile([
                'student_id' => $student->id,
                'document_type_id' => $document->document_type_id,
                'title' => $document->title,
                'remarks' => $document->remarks ?? 'Uploaded with registration submission.',
            ], $document->file_path, [
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => (int) $document->file_size,
            ], $collegeId, $actorId);

            // The row is metadata for a file that is now owned by the student
            // document; deleting it keeps one record per stored blob.
            $document->delete();
        }
    }

    /**
     * The subset of a submitted payload that may reach `students`.
     *
     * Only real, fillable columns survive: a wizard-only key (qualification
     * rows, an upload field, a client-supplied *_path) can therefore never reach
     * the model, where an unknown attribute would either be silently dropped or
     * blow up the insert. Server-owned columns are excluded here as well, so a
     * resubmission cannot set its own status or numbers.
     *
     * The single-POST registration form also sends the portrait and the
     * removal flags, which StudentService extracts before the insert; those
     * keys are passed through unchanged (they are never written as columns).
     */
    private function studentAttributes(array $data, bool $columnsOnly = false): array
    {
        $fileKeys = $columnsOnly ? [] : ['photo', 'remove_photo', 'remove_aadhaar', 'remove_govt_id'];

        $serverOwned = [
            'college_id', 'student_number', 'admission_application_id', 'status',
            'registration_status', 'registration_submitted_at', 'created_by', 'updated_by',
            'aadhaar_last4', 'aadhaar_hash',
        ];

        $fillable = (new Student())->getFillable();

        $attributes = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $serverOwned, true)) {
                continue;
            }

            if (! in_array($key, $fillable, true) && ! in_array($key, $fileKeys, true)) {
                continue;
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Stable identity fingerprint for duplicate-submission detection. Only
     * derived hashes and non-sensitive identity fields are used; the plaintext
     * Aadhaar number is never stored in the approval payload or fingerprint.
     */
    private function fingerprint(array $data): string
    {
        $aadhaar = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        return hash('sha256', json_encode([
            'first_name' => mb_strtolower(trim((string) ($data['first_name'] ?? ''))),
            'last_name' => mb_strtolower(trim((string) ($data['last_name'] ?? ''))),
            'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'aadhaar_hash' => $aadhaar !== null ? Aadhaar::hash($aadhaar) : null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Double-click / repeated POST guard for the same person. Phone and Aadhaar
     * duplicates are already blocked by the Form Request; this adds a
     * name + date-of-birth fallback for records without either identifier.
     */
    private function duplicatePending(array $data, int $collegeId): ?Student
    {
        $aadhaar = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        return Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('registration_status', Student::REGISTRATION_PENDING)
            ->where('first_name', $data['first_name'] ?? '')
            ->where('last_name', $data['last_name'] ?? null)
            ->where('date_of_birth', $data['date_of_birth'] ?? null)
            ->when(
                isset($data['phone']) && $data['phone'] !== '',
                fn ($query) => $query->where('phone', $data['phone']),
                fn ($query) => $query->whereNull('phone'),
            )
            ->when(
                $aadhaar !== null,
                fn ($query) => $query->where('aadhaar_hash', Aadhaar::hash($aadhaar)),
            )
            ->lockForUpdate()
            ->first();
    }
}
