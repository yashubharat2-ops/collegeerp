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
            $existing = $this->duplicatePending($data, $collegeId);
            if ($existing) {
                $request = ApprovalRequest::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('module', 'student')
                    ->where('action', 'registration_approval')
                    ->where('target_type', 'student.registration_approval')
                    ->where('target_id', $existing->id)
                    ->where('status', ApprovalRequest::STATUS_PENDING)
                    ->first();

                if ($request) {
                    return ['student' => $existing, 'approval_request' => $request, 'created' => false];
                }
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

            $approvalRequest = $this->approvals->submitRequest([
                'module' => 'student',
                'action' => 'registration_approval',
                'target_type' => 'student.registration_approval',
                'target_id' => $student->id,
                'title' => 'Student registration: '.$student->student_number,
                'description' => 'Registration submitted by '.$actorId.' for '.$student->first_name.' '.$student->last_name.'.',
                'payload' => [
                    'student_id' => $student->id,
                    'academic_year_id' => $data['academic_year_id'] ?? null,
                    'program_id' => $data['program_id'] ?? null,
                    'section_id' => $data['section_id'] ?? null,
                    'enrollment_date' => $data['enrollment_date'] ?? null,
                    'admission_date' => $studentData['admission_date'] ?? now()->toDateString(),
                ],
                'previous_data' => [
                    'status' => 'inactive',
                    'registration_status' => Student::REGISTRATION_PENDING,
                ],
                'request_reason' => $studentData['remarks'] ?? null,
            ], $collegeId, $actorId);

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
