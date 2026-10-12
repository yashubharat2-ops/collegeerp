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
                // Adoption is metadata-only: retain valid legacy values and do
                // not replay issuance mutations against historical enrollments.
                if ($transfer->isTcIssued()) {
                    $this->ensure(
                        $this->hasCompleteLegacyTcIssueMetadata($transfer),
                        'The linked legacy transfer certificate is missing a valid TC number or issue date and cannot be adopted.'
                    );
                } else {
                    $transfer = $this->transfers->issue($transfer, $collegeId, auth()->id(), collegeLockHeld: true);
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

    /**
     * Whether an already-issued transfer carries a complete, trustworthy TC
     * issue record, so it can be ADOPTED (metadata only) rather than reissued.
     *
     * `tc_issue_date` is a DATE column, but it reaches this check in TWO
     * legitimate raw shapes and both must be accepted:
     *
     * - `Y-m-d` — what a real DATE column returns on MySQL/MariaDB/Postgres/
     *   SQL Server, and what a legacy import writes directly.
     * - `Y-m-d 00:00:00` — what Eloquent itself persists. The attribute is cast
     *   to `date`, and a date cast is written through Model::fromDateTime(),
     *   which formats with the connection grammar's date format `Y-m-d H:i:s`
     *   (see Illuminate\Database\Grammar::getDateFormat()). sqlite — the test
     *   connection — has no real DATE type and returns the stored text
     *   verbatim, so the midnight time component survives to this point.
     *
     * Accepting only the bare shape is what made every ORM-written TC look
     * like corrupt legacy data, while a raw-written bad date was still
     * rejected — so the failure presented as a data problem instead of a
     * format assumption.
     *
     * Validation stays strict:
     * - a non-midnight time is refused, because a DATE column cannot produce
     *   one, so such a row was written out-of-band and is not trusted legacy
     *   data;
     * - the day is parsed with `!` so no component may be omitted;
     * - zero warnings and zero errors are required;
     * - the parsed day must round-trip to the input, which is what refuses a
     *   non-existent date such as 2025-02-30 (it parses to 2025-03-02) rather
     *   than letting it be normalised.
     */
    private function hasCompleteLegacyTcIssueMetadata(StudentTransfer $transfer): bool
    {
        $number = $transfer->getRawOriginal('tc_number');

        if (! is_string($number) || trim($number) === '') {
            return false;
        }

        $issueDate = $transfer->getRawOriginal('tc_issue_date');

        if (! is_string($issueDate)) {
            return false;
        }

        $value = trim($issueDate);

        // A bare date, or the midnight timestamp the `date` cast persists.
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) !== 1) {
            return false;
        }

        $day = substr($value, 0, 10);

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        if ($parsed === false) {
            return false;
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }

        return $parsed->format('Y-m-d') === $day;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['certificate' => $message]);
        }
    }
}
