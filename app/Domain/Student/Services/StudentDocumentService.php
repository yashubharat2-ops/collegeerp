<?php

namespace App\Domain\Student\Services;

use App\Models\AdmissionDocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure student document storage, verification and replacement.
 *
 * Threat model handled here (never in the view, never trusting the browser):
 *
 * - The stored path is ALWAYS generated server-side as
 *   `students/{college_id}/{student_id}/{uuid}.{ext}` on the `private` disk.
 *   A user-supplied path is never persisted, so path traversal and arbitrary
 *   file placement are impossible by construction.
 * - The extension is sanitised to `[a-z0-9]` and rejected outright when it is
 *   on the executable/scriptable blacklist, so no uploaded file can ever be
 *   mistaken for code even if the private disk were later exposed.
 * - Size and extension limits come from the reused AdmissionDocumentType
 *   configuration (per college), plus hard Form-Request limits.
 * - Rows are soft-deleted and the stored file is RETAINED, so document history
 *   survives deletion; the path is snapshotted into the audit log.
 *
 * Tenant safety: every student/type lookup goes through CollegeScope, so a
 * foreign college's student or document type resolves to a 404.
 */
class StudentDocumentService
{
    public const AUDITED = [
        'id', 'student_id', 'document_type_id', 'title', 'file_path', 'original_filename',
        'mime_type', 'file_size', 'issue_date', 'expiry_date', 'verification_status',
        'verified_by', 'verified_at', 'rejection_remarks', 'remarks',
    ];

    /** Hard default ceiling (KB) when no document type configuration applies. */
    public const DEFAULT_MAX_SIZE_KB = 5120;

    /**
     * Extensions that must never be accepted, regardless of type configuration.
     * Defence in depth: files live on a non-served private disk, but a stored
     * script is a latent risk if the disk is ever copied or mis-served.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb', 'sh',
        'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'so', 'msi', 'scr', 'vbs',
        'js', 'jar', 'htm', 'html', 'shtml', 'svg', 'xhtml',
    ];

    public function __construct(
        private readonly SecureFileService $files,
        private readonly AuditLogService $audit,
    ) {}

    public function store(array $data, UploadedFile $file, int $collegeId, ?int $userId = null): StudentDocument
    {
        return DB::transaction(function () use ($data, $file, $collegeId, $userId): StudentDocument {
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey((int) $data['student_id'])
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            $type = null;
            if (! empty($data['document_type_id'])) {
                // Tenant-scoped: a foreign college's document type 404s.
                $type = AdmissionDocumentType::query()->find((int) $data['document_type_id']);

                if (! $type) {
                    abort(404, 'Document type not found in this college context.');
                }
            }

            $storedPath = $this->storeFile($file, $type, $collegeId, $student->id);

            $document = StudentDocument::create([
                'college_id' => $collegeId,
                'student_id' => $student->id,
                'document_type_id' => $type?->id,
                'title' => $data['title'],
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'verification_status' => 'pending',
                'uploaded_by' => $userId,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_document.uploaded', $document, [], $document->only(self::AUDITED));

            return $document;
        });
    }

    /**
     * Replace the file of an existing document.
     *
     * Verification is reset (a new file has not been verified) and the previous
     * path is snapshotted into the audit log, which is the document's history.
     */
    public function reupload(StudentDocument $document, UploadedFile $file, ?int $userId = null): StudentDocument
    {
        return DB::transaction(function () use ($document, $file, $userId): StudentDocument {
            $type = $document->document_type_id
                ? AdmissionDocumentType::query()->find($document->document_type_id)
                : null;

            $oldPath = $document->file_path;
            $storedPath = $this->storeFile($file, $type, (int) $document->college_id, (int) $document->student_id);

            $old = $document->only(self::AUDITED);

            $document->update([
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'verification_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_remarks' => null,
                'uploaded_by' => $userId ?? $document->uploaded_by,
                'updated_by' => $userId,
            ]);

            // Remove the superseded blob only after the new one is committed.
            if ($oldPath && $oldPath !== $storedPath) {
                $this->files->delete($oldPath);
            }

            $this->audit->record('student_document.replaced', $document, $old, $document->only(self::AUDITED));

            return $document;
        });
    }

    public function updateMetadata(StudentDocument $document, array $data, ?int $userId = null): StudentDocument
    {
        $old = $document->only(self::AUDITED);

        $changes = array_filter([
            'document_type_id' => $data['document_type_id'] ?? null,
            'title' => $data['title'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (array_key_exists('remarks', $data)) {
            $changes['remarks'] = $data['remarks'];
        }

        $changes['updated_by'] = $userId;

        $document->update($changes);

        $this->audit->record('student_document.updated', $document, $old, $document->only(self::AUDITED));

        return $document;
    }

    public function verify(StudentDocument $document, int $verifiedBy, ?string $remarks = null): StudentDocument
    {
        $old = $document->only(self::AUDITED);

        $document->update([
            'verification_status' => 'verified',
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
            'rejection_remarks' => null,
            'remarks' => $remarks ?? $document->remarks,
            'updated_by' => $verifiedBy,
        ]);

        $this->audit->record('student_document.verified', $document, $old, $document->only(self::AUDITED));

        return $document;
    }

    public function reject(StudentDocument $document, int $verifiedBy, string $rejectionRemarks): StudentDocument
    {
        $old = $document->only(self::AUDITED);

        $document->update([
            'verification_status' => 'rejected',
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
            'rejection_remarks' => $rejectionRemarks,
            'updated_by' => $verifiedBy,
        ]);

        $this->audit->record('student_document.rejected', $document, $old, $document->only(self::AUDITED));

        return $document;
    }

    /**
     * Soft-delete the record. The stored file is intentionally KEPT so the
     * document history stays retrievable/auditable; the path is snapshotted
     * into the audit entry.
     */
    public function delete(StudentDocument $document, ?int $userId = null): void
    {
        $snapshot = $document->only(self::AUDITED);

        $document->update(['updated_by' => $userId]);
        $document->delete();

        $this->audit->record('student_document.deleted', $document, $snapshot, []);
    }

    /**
     * Store an upload under a tenant-scoped private path with a generated name.
     *
     * The original filename is preserved in the database for display only and
     * never influences the stored path.
     */
    private function storeFile(UploadedFile $file, ?AdmissionDocumentType $type, int $collegeId, int $studentId): string
    {
        $this->assertAcceptableFile($file, $type);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }
        // Alphanumeric only: strips path separators, dots and null bytes.
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for student documents.',
            ]);
        }

        $directory = sprintf('students/%d/%d', $collegeId, $studentId);

        $storedPath = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'private');

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored. Please try again.',
            ]);
        }

        return $storedPath;
    }

    /**
     * Enforce size/extension/MIME limits from the reused document-type config,
     * falling back to safe defaults when no type is selected.
     */
    private function assertAcceptableFile(UploadedFile $file, ?AdmissionDocumentType $type): void
    {
        $maxKb = $type?->max_size_kb ?: self::DEFAULT_MAX_SIZE_KB;

        if ((int) $file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'The file exceeds the maximum allowed size of '.$maxKb.' KB.',
            ]);
        }

        $allowed = $type ? $type->allowedExtensionsArray() : [];

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }

        if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file extension .'.$extension.' is not allowed for document type '.$type->name.'. Allowed: '.implode(', ', $allowed).'.',
            ]);
        }

        // Reject content types that indicate script/executable content even
        // when the extension looked harmless.
        $mime = strtolower((string) $file->getMimeType());
        $dangerousMimes = [
            'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/php',
            'text/html', 'application/xhtml+xml', 'image/svg+xml',
            'application/x-executable', 'application/x-sh', 'application/x-shellscript',
            'application/x-msdownload', 'application/x-elf', 'application/x-sharedlib',
        ];

        if (in_array($mime, $dangerousMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for student documents.',
            ]);
        }
    }

    /**
     * Shared guard for every read path: the stored path must be a plain
     * relative private-disk key. Rejects traversal, absolute paths, stream
     * wrappers and null bytes even though the value is server-generated.
     */
    public static function assertSafePath(?string $path): string
    {
        $path = (string) $path;

        if ($path === ''
            || str_contains($path, '..')
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || str_contains($path, "\0")
            || preg_match('#^[a-z0-9]+://#i', $path)
        ) {
            abort(403, 'Invalid document path.');
        }

        return $path;
    }

    /**
     * A safe display name for a download response.
     *
     * `original_filename` / `tc_original_filename` are user-supplied text kept
     * for the operator's benefit only — they are never used as a path, which is
     * why a traversal attempt in the name cannot affect where a file is stored.
     *
     * Before the value reaches a Content-Disposition header it is reduced to a
     * single safe segment. The rules follow what Symfony's HeaderUtils accepts
     * for a filename fallback: ASCII only, no "/", "\" or "%" (each of which
     * makes it throw), and no control characters or quotes — so a crafted name
     * can neither escape the header nor turn a legitimate download into a 500.
     * The name as uploaded stays visible in the module listings.
     */
    public static function safeDownloadName(?string $name, string $fallback = 'document'): string
    {
        $name = str_replace(['\\', '/'], '', (string) $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\']/', '', $name);
        $name = (string) preg_replace('/[^\x20-\x7E]/', '', $name);
        $name = str_replace(['..', '%'], '', $name);
        $name = trim($name, " \t\n\r\0\x0B.");

        return $name === '' ? $fallback : $name;
    }
}
