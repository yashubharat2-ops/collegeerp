<?php

namespace App\Domain\HR\Services;

use App\Models\AdmissionDocumentType;
use App\Models\EmployeeDocument;
use App\Models\Faculty;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure storage boundary for HR employee documents.
 *
 * Employee documents follow the Student Documents pattern: the row is tenant
 * scoped, blobs live on the private disk, paths are generated with a UUID, and
 * controllers stream a file only after policy authorization and path checks.
 */
class EmployeeDocumentService
{
    public const DEFAULT_MAX_SIZE_KB = 10240;

    public const AUDITED = [
        'id', 'faculty_id', 'document_name', 'document_type', 'document_type_id',
        'file_path', 'original_filename', 'mime_type', 'file_size',
        'issue_date', 'expiry_date', 'remarks',
    ];

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

    public function store(array $data, UploadedFile $file, int $collegeId, ?int $userId = null): EmployeeDocument
    {
        return DB::transaction(function () use ($data, $file, $collegeId, $userId): EmployeeDocument {
            $employee = Faculty::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->find((int) $data['faculty_id']);

            if (! $employee) {
                abort(404, 'Employee not found in this college context.');
            }

            $type = $this->documentType($data['document_type_id'] ?? null);
            $storedPath = $this->storeFile($file, $type, $collegeId, $employee->id);
            $documentType = filled($data['document_type'] ?? null)
                ? $data['document_type']
                : $type?->name;

            $document = EmployeeDocument::create([
                'college_id' => $collegeId,
                'faculty_id' => $employee->id,
                'document_name' => $data['document_name'],
                'document_type' => $documentType,
                'document_type_id' => $type?->id,
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'uploaded_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('employee_document.uploaded', $document, [], $document->only(self::AUDITED));

            return $document;
        });
    }

    public function reupload(EmployeeDocument $document, UploadedFile $file, ?int $userId = null): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $file, $userId): EmployeeDocument {
            $type = $this->documentType($document->document_type_id);
            $oldPath = $document->file_path;
            $storedPath = $this->storeFile($file, $type, (int) $document->college_id, (int) $document->faculty_id);
            $old = $document->only(self::AUDITED);

            $document->update([
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $userId ?? $document->uploaded_by,
                'updated_by' => $userId,
            ]);

            if ($oldPath && $oldPath !== $storedPath) {
                // Replace only a valid private-disk key; a tampered database
                // value must never become a filesystem path.
                $this->files->delete(self::assertSafePath($oldPath, (int) $document->college_id, (int) $document->faculty_id));
            }

            $this->audit->record('employee_document.replaced', $document, $old, $document->only(self::AUDITED));

            return $document;
        });
    }

    public function updateMetadata(EmployeeDocument $document, array $data, ?int $userId = null): EmployeeDocument
    {
        $old = $document->only(self::AUDITED);
        $changes = [
            'document_name' => $data['document_name'],
            'document_type' => $data['document_type'] ?? null,
            'document_type_id' => null,
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'updated_by' => $userId,
        ];

        $type = $this->documentType($data['document_type_id'] ?? null);
        $changes['document_type_id'] = $type?->id;
        if (blank($changes['document_type'])) {
            $changes['document_type'] = $type?->name;
        }

        $document->update($changes);
        $this->audit->record('employee_document.updated', $document, $old, $document->only(self::AUDITED));

        return $document;
    }

    public function delete(EmployeeDocument $document, ?int $userId = null): void
    {
        $snapshot = $document->only(self::AUDITED);
        $document->update(['updated_by' => $userId]);
        $document->delete();
        $this->audit->record('employee_document.deleted', $document, $snapshot, []);
    }

    private function documentType(mixed $id): ?AdmissionDocumentType
    {
        if (blank($id)) {
            return null;
        }

        $type = AdmissionDocumentType::query()->find((int) $id);
        if (! $type) {
            abort(404, 'Document type not found in this college context.');
        }

        return $type;
    }

    private function storeFile(UploadedFile $file, ?AdmissionDocumentType $type, int $collegeId, int $facultyId): string
    {
        $this->assertAcceptableFile($file, $type);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for employee documents.',
            ]);
        }

        $path = $file->storeAs(
            sprintf('employee-documents/%d/%d', $collegeId, $facultyId),
            Str::uuid()->toString().'.'.$extension,
            'private'
        );

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored. Please try again.',
            ]);
        }

        return $path;
    }

    private function assertAcceptableFile(UploadedFile $file, ?AdmissionDocumentType $type): void
    {
        $maxKb = $type?->max_size_kb ?: self::DEFAULT_MAX_SIZE_KB;
        if ((int) $file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'The file exceeds the maximum allowed size of '.$maxKb.' KB.',
            ]);
        }

        $allowed = $type?->allowedExtensionsArray() ?? [];
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }

        if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file extension .'.$extension.' is not allowed for this document type.',
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (in_array($mime, [
            'text/x-php', 'application/x-php', 'application/x-httpd-php',
            'application/php', 'text/html', 'application/xhtml+xml',
            'image/svg+xml', 'application/x-executable', 'application/x-sh',
            'application/x-shellscript', 'application/x-msdownload',
        ], true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for employee documents.',
            ]);
        }
    }

    public static function assertSafePath(?string $path, ?int $collegeId = null, ?int $facultyId = null): string
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

        if ($collegeId !== null && $facultyId !== null) {
            $prefix = sprintf('employee-documents/%d/%d/', $collegeId, $facultyId);
            if (! str_starts_with($path, $prefix)) {
                abort(403, 'Invalid document path.');
            }
        }

        return $path;
    }

    public static function safeDownloadName(?string $name, string $fallback = 'employee-document'): string
    {
        $name = str_replace(['\\', '/'], '', (string) $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\']/', '', $name);
        $name = (string) preg_replace('/[^\x20-\x7E]/', '', $name);
        $name = str_replace(['..', '%'], '', $name);
        $name = trim($name, " \t\n\r\0\x0B.");

        return $name === '' ? $fallback : $name;
    }
}
