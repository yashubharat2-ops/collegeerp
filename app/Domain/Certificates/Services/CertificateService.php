<?php

namespace App\Domain\Certificates\Services;

use App\Domain\Certificates\Actions\GenerateTcNumber;
use App\Domain\Certificates\CertificateTypes;
use App\Models\CertificateIssuance;
use App\Models\CertificateTemplate;
use App\Models\College;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransfer;
use App\Domain\Student\Services\StudentService;
use App\Domain\Student\Services\StudentTransferService;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateService
{
    private const ALLOWED_TC_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly StudentService $students,
        private readonly GenerateTcNumber $tcNumbers,
    ) {}

    /** Issue a TC on the existing transfer row, while applying the student exit status transition. */
    public function issueTransfer(StudentTransfer $transfer, int $collegeId, ?int $userId = null, ?string $issueDate = null, ?UploadedFile $file = null, ?int $templateId = null): StudentTransfer
    {
        return DB::transaction(function () use ($transfer, $collegeId, $userId, $issueDate, $file, $templateId): StudentTransfer {
            if ((int) $transfer->college_id !== $collegeId) abort(404);
            if (! $transfer->isApproved()) {
                throw ValidationException::withMessages(['status' => 'Only an approved transfer request can be issued. Current status: '.$transfer->status.'.']);
            }
            if ($transfer->isTcIssued()) {
                throw ValidationException::withMessages(['tc_status' => 'The transfer certificate has already been issued ('.$transfer->tc_number.').']);
            }
            $student = Student::withoutGlobalScopes()->where('college_id', $collegeId)->whereKey((int) $transfer->student_id)->lockForUpdate()->first();
            if (! $student) abort(404, 'Student not found in this college context.');

            if ($templateId !== null && ! CertificateTemplate::query()->where('college_id', $collegeId)->where('type', 'tc')->where('is_active', true)->find($templateId)) {
                throw ValidationException::withMessages(['template_id' => 'Choose an active TC template belonging to this college.']);
            }
            $date = $issueDate ?: now()->toDateString();
            $number = $this->tcNumbers->execute($collegeId, substr($date, 0, 4));
            $old = $transfer->only(StudentTransferService::AUDITED);
            $changes = ['tc_number' => $number, 'tc_issue_date' => $date, 'tc_status' => 'issued', 'certificate_template_id' => $templateId, 'updated_by' => $userId];
            if ($file) {
                $path = $this->storeTcFile($file, $collegeId, (int) $student->id);
                $changes += ['tc_file_path' => $path, 'tc_original_filename' => $file->getClientOriginalName(), 'tc_file_size' => $file->getSize()];
            }
            $transfer->update($changes);

            if ($transfer->enrollment_id) {
                $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->find($transfer->enrollment_id);
                if ($enrollment && $enrollment->status !== 'withdrawn') $this->students->updateEnrollment($enrollment, ['status' => 'withdrawn'], $collegeId);
            }
            if ($student->status !== 'withdrawn') $this->students->updateStudent($student, ['status' => 'withdrawn']);
            $this->audit->record('student_transfer.issued', $transfer, $old, $transfer->only(StudentTransferService::AUDITED));
            return $transfer->refresh()->load('student');
        });
    }

    private function storeTcFile(UploadedFile $file, int $collegeId, int $studentId): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') $extension = strtolower((string) $file->extension());
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);
        if (! in_array($extension, self::ALLOWED_TC_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['tc_file' => 'The TC file must be a PDF, JPG or PNG.']);
        }
        if ((int) $file->getSize() > 5120 * 1024) {
            throw ValidationException::withMessages(['tc_file' => 'The TC file exceeds the maximum allowed size of 5120 KB.']);
        }
        $directory = sprintf('students/%d/%d/tc', $collegeId, $studentId);
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'private');
        if ($path === false) throw ValidationException::withMessages(['tc_file' => 'The TC file could not be stored. Please try again.']);
        return $path;
    }

    public function issue(array $data, int $collegeId, int $userId): CertificateIssuance
    {
        if (! CertificateTypes::contains($data['type'] ?? null) || $data['type'] === 'tc') {
            throw ValidationException::withMessages(['type' => 'TC issuance is managed from the Transfer Certificate request only.']);
        }

        return DB::transaction(function () use ($data, $collegeId, $userId) {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
            $student = Student::query()->whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            $enrollment = null;
            if (! empty($data['enrollment_id'])) {
                $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->find($data['enrollment_id']);
                if (! $enrollment) throw ValidationException::withMessages(['enrollment_id' => 'Choose an enrollment belonging to this student.']);
            }
            $template = ! empty($data['template_id']) ? CertificateTemplate::query()->findOrFail($data['template_id']) : null;
            if ($template && ($template->type !== $data['type'] || ! $template->is_active)) {
                throw ValidationException::withMessages(['template_id' => 'Choose an active template for this certificate type.']);
            }

            $prefix = $data['type'] === 'bonafide' ? 'BON' : 'CHAR';
            $year = substr($data['issued_at'] ?? now()->toDateString(), 0, 4);
            $last = CertificateIssuance::query()->where('type', $data['type'])->where('certificate_number', 'like', "$prefix-$year-%")->orderByDesc('id')->value('certificate_number');
            $sequence = $last ? ((int) Str::afterLast($last, '-') + 1) : 1;
            $number = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
            $content = $template?->body ?? CertificateTypes::label($data['type'])."\n\nThis is to certify that {{student_name}} ({{student_number}}) is/was a student of {{college_name}}.";
            $content = $this->renderStudentTemplate($content, $student);

            $issuance = CertificateIssuance::create([
                'college_id' => $collegeId, 'student_id' => $student->id, 'enrollment_id' => $enrollment?->id,
                'template_id' => $template?->id, 'type' => $data['type'], 'certificate_number' => $number,
                'issued_at' => $data['issued_at'] ?? now()->toDateString(), 'purpose' => $data['purpose'] ?? null,
                'rendered_content' => $content, 'issued_by' => $userId,
            ]);
            $this->audit->record('certificate.issued', $issuance, [], $issuance->only(['id', 'student_id', 'type', 'certificate_number', 'issued_at']));
            return $issuance;
        });
    }

    /** Resolve the supported, non-recursive template tokens from the existing student and college snapshots. */
    private function renderStudentTemplate(string $template, Student $student): string
    {
        return strtr($template, [
            '{{student_name}}' => $student->fullName(),
            '{{student_number}}' => $student->student_number,
            '{{college_name}}' => $student->college?->name ?? 'the institution',
            '{{first_name}}' => $student->first_name,
            '{{middle_name}}' => $student->middle_name ?? '',
            '{{last_name}}' => $student->last_name,
            '{{date_of_birth}}' => $student->date_of_birth?->format('Y-m-d') ?? '',
            '{{admission_date}}' => $student->admission_date?->format('Y-m-d') ?? '',
        ]);
    }
}
