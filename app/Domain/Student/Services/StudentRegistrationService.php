<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Support\Aadhaar;
use App\Models\AcademicYear;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated Student profile payload
     * @param  array<int, UploadedFile>  $files  optional registration documents
     * @return array{student: Student, approval_request: ApprovalRequest, created: bool}
     */
    public function register(array $data, array $files, int $collegeId, int $actorId): array
    {
        if (! $this->approvals->isApprovalRequired($collegeId, 'student', 'registration_approval')) {
            throw ValidationException::withMessages([
                'approval' => 'Student registration approval is disabled for this college. Re-enable it in Approval Settings before submitting registrations.',
            ]);
        }

        return DB::transaction(function () use ($data, $files, $collegeId, $actorId): array {
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
                $reusableStudent->update([
                    'status' => 'inactive',
                    'registration_status' => Student::REGISTRATION_PENDING,
                ]);
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
                'academic_year_id', 'program_id', 'section_id', 'enrollment_date',
                'registration_document_type_id', 'registration_documents',
            ]);
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
                'program_id' => $data['program_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'enrollment_date' => $data['enrollment_date'] ?? null,
                'admission_date' => $data['admission_date'] ?? now()->toDateString(),
            ],
            'previous_data' => [
                'status' => 'inactive',
                'registration_status' => Student::REGISTRATION_PENDING,
            ],
            'request_reason' => $data['remarks'] ?? null,
        ], $collegeId, $actorId);
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
