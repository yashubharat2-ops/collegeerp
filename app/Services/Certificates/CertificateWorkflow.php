<?php

namespace App\Services\Certificates;

use App\Domain\Student\Services\StudentTransferService;
use App\Models\{Certificate, CertificateTemplate, CertificateType, College, Student, StudentEnrollment, StudentTransfer};
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CertificateWorkflow
{
    public const PLACEHOLDERS = ['college_name', 'certificate_type', 'student_name', 'student_number', 'enrollment_number', 'program_name', 'academic_year', 'transfer_date', 'destination_institution', 'purpose', 'certificate_number', 'issue_date'];

    public function __construct(private AuditLogService $audit, private StudentTransferService $transfers) {}

    public function request(array $data): Certificate
    {
        return DB::transaction(function () use ($data) {
            $collegeId = app(TenantContext::class)->require()->id;
            College::whereKey($collegeId)->lockForUpdate()->firstOrFail();
            $type = CertificateType::findOrFail($data['certificate_type_id']);
            $enrollment = StudentEnrollment::findOrFail($data['student_enrollment_id']);
            $student = Student::findOrFail($enrollment->student_id);
            $transfer = null;
            if ($type->builtin_key === 'transfer') {
                $transfer = StudentTransfer::where('student_id', $student->id)
                    ->where('enrollment_id', $enrollment->id)->find($data['student_transfer_id'] ?? null);
                $this->ensure($transfer && $transfer->status === 'approved' && $transfer->tc_status !== 'cancelled', 'Select an approved transfer belonging to this student and enrollment.');
                $this->ensure(! Certificate::where('student_transfer_id', $transfer->id)->exists(), 'This transfer already has a certificate request.');
            } else {
                $this->ensure(empty($data['student_transfer_id']), 'Only a Transfer Certificate may link a student transfer.');
            }
            $certificate = Certificate::create([
                'college_id' => $collegeId, 'certificate_type_id' => $type->id,
                'student_id' => $student->id, 'student_enrollment_id' => $enrollment->id,
                'student_transfer_id' => $transfer?->id, 'purpose' => $data['purpose'] ?? null,
                'status' => 'requested', 'requested_by' => auth()->id(),
            ]);
            $this->audit->record('certificate.requested', $certificate, [], $certificate->only(['status', 'student_id', 'student_enrollment_id', 'student_transfer_id', 'certificate_type_id']));
            return $certificate;
        });
    }

    public function generate(int $id, int $templateId): Certificate
    {
        return DB::transaction(function () use ($id, $templateId) {
            $certificate = Certificate::lockForUpdate()->findOrFail($id);
            $this->ensure($certificate->status === 'requested', 'Only a requested certificate can be generated.');
            $template = CertificateTemplate::where('certificate_type_id', $certificate->certificate_type_id)->findOrFail($templateId);
            $student = Student::findOrFail($certificate->student_id);
            $enrollment = StudentEnrollment::where('student_id', $student->id)->findOrFail($certificate->student_enrollment_id);
            $transfer = $certificate->transfer;
            $certificate->update([
                'certificate_template_id' => $template->id, 'template_snapshot' => $template->body,
                'data_snapshot' => [
                    'college_name' => app(TenantContext::class)->require()->name,
                    'certificate_type' => $certificate->type->name,
                    'student_name' => $student->fullName(), 'student_number' => $student->student_number,
                    'enrollment_number' => $enrollment->enrollment_number,
                    'program_name' => $enrollment->program?->name ?? '', 'academic_year' => $enrollment->academicYear?->name ?? '',
                    'transfer_date' => $transfer?->transfer_date?->toDateString() ?? '',
                    'destination_institution' => $transfer?->destination_institution ?? '', 'purpose' => $certificate->purpose ?? '',
                ],
                'status' => 'generated', 'generated_at' => now(), 'generated_by' => auth()->id(),
            ]);
            $this->audit->record('certificate.generated', $certificate, ['status' => 'requested'], ['status' => 'generated', 'template_id' => $template->id]);
            return $certificate;
        });
    }

    public function issue(int $id): Certificate
    {
        return DB::transaction(function () use ($id) {
            $collegeId = app(TenantContext::class)->require()->id;
            College::whereKey($collegeId)->lockForUpdate()->firstOrFail();
            $certificate = Certificate::lockForUpdate()->findOrFail($id);
            $this->ensure($certificate->status === 'generated', 'Only a generated certificate can be issued.');
            $type = CertificateType::lockForUpdate()->findOrFail($certificate->certificate_type_id);
            $issuedAt = now();
            if ($type->builtin_key === 'transfer') {
                abort_unless(auth()->user()?->hasPermission('student_transfers.approve', $collegeId), 403);
                $transfer = StudentTransfer::where('student_id', $certificate->student_id)
                    ->where('enrollment_id', $certificate->student_enrollment_id)->lockForUpdate()->findOrFail($certificate->student_transfer_id);
                $this->ensure($transfer->status === 'approved' && $transfer->tc_status !== 'cancelled', 'The linked transfer is no longer approved.');
                // Adopt an already-issued legacy TC without issuing it twice or changing its number/date.
                if (! $transfer->isTcIssued()) {
                    $transfer = $this->transfers->issue($transfer, $collegeId, auth()->id());
                }
                $number = $transfer->tc_number;
                $issuedAt = $transfer->tc_issue_date;
            } else {
                $number = sprintf('%s-%s-%06d', $type->code, now()->format('Y'), $type->next_number);
                $type->increment('next_number');
            }
            $certificate->update(['status' => 'issued', 'number' => $number, 'issued_at' => $issuedAt, 'issued_by' => auth()->id()]);
            $this->audit->record('certificate.issued', $certificate, ['status' => 'generated'], ['status' => 'issued', 'number' => $number]);
            return $certificate;
        });
    }

    public function verify(string $number): Certificate
    {
        return DB::transaction(function () use ($number) {
            $certificate = Certificate::where('number', $number)->where('status', 'issued')->lockForUpdate()->firstOrFail();
            if ($certificate->student_transfer_id) {
                $this->ensure($certificate->transfer?->tc_status === 'issued', 'The linked transfer certificate is not valid.');
            }
            $certificate->update(['last_verified_at' => now(), 'last_verified_by' => auth()->id(), 'verification_count' => $certificate->verification_count + 1]);
            $this->audit->record('certificate.verified', $certificate, [], ['number' => $number, 'status' => 'issued']);
            return $certificate;
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['certificate' => $message]);
        }
    }
}
