<?php

namespace App\Domain\Transport\Services;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure storage boundary for vehicle documents (Transport Phase 2).
 *
 * Vehicle documents follow the Student Documents / Employee Documents
 * pattern exactly:
 *
 * - The stored path is ALWAYS generated server-side as
 *   `vehicle-documents/{college_id}/{vehicle_id}/{uuid}.{ext}` on the `private`
 *   disk. A user-supplied path is never persisted, so path traversal and
 *   arbitrary file placement are impossible by construction.
 * - The extension is sanitised to `[a-z0-9]` and rejected outright when it is
 *   on the executable/scriptable blacklist.
 * - Size and MIME limits are enforced here as well as in the Form Request,
 *   because server-side validation is authoritative.
 * - Rows are soft-deleted and the stored file is RETAINED, so document history
 *   survives deletion; the path is snapshotted into the audit log.
 *
 * Tenant safety: the vehicle is resolved WITHOUT global scopes but filtered by
 * the explicit tenant id (a foreign college's vehicle resolves to a 404), and
 * every read path re-checks that the stored file key is a plain relative
 * private-disk key under this document's own vehicle directory.
 */
class VehicleDocumentService
{
    /** Hard ceiling (KB) for vehicle documents. */
    public const DEFAULT_MAX_SIZE_KB = 5120;

    public const AUDITED = [
        'id', 'vehicle_id', 'document_type', 'document_number', 'issue_date',
        'expiry_date', 'file_path', 'original_filename', 'mime_type', 'file_size',
        'remarks', 'uploaded_by',
    ];

    /**
     * Extensions that must never be accepted. Defence in depth: files live on a
     * non-served private disk, but a stored script is a latent risk if the disk
     * is ever copied or mis-served.
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
    ) {
    }

    /**
     * @param  array{vehicle_id: int|string, document_type: string, document_number?: string|null, issue_date?: string|null, expiry_date?: string|null, remarks?: string|null}  $data
     */
    public function store(array $data, UploadedFile $file, int $collegeId, ?int $userId = null): VehicleDocument
    {
        return DB::transaction(function () use ($data, $file, $collegeId, $userId): VehicleDocument {
            $vehicle = $this->resolveVehicle($collegeId, (int) $data['vehicle_id']);

            $storedPath = $this->storeFile($file, $collegeId, (int) $vehicle->id);

            $document = VehicleDocument::create([
                // Tenant and actor fields are server-controlled, never input.
                'college_id' => $collegeId,
                'vehicle_id' => $vehicle->id,
                'document_type' => trim((string) $data['document_type']),
                'document_number' => ($data['document_number'] ?? null) ?: null,
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'uploaded_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('vehicle_document.uploaded', $document, [], $document->only(self::AUDITED));

            return $document;
        });
    }

    /**
     * Replace the file of an existing document. The previous path is
     * snapshotted into the audit log — the document's history.
     */
    public function reupload(VehicleDocument $document, UploadedFile $file, ?int $userId = null): VehicleDocument
    {
        return DB::transaction(function () use ($document, $file, $userId): VehicleDocument {
            $oldPath = $document->file_path;
            $storedPath = $this->storeFile($file, (int) $document->college_id, (int) $document->vehicle_id);

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
                $this->files->delete(self::assertSafePath($oldPath, (int) $document->college_id, (int) $document->vehicle_id));
            }

            $this->audit->record('vehicle_document.replaced', $document, $old, $document->only(self::AUDITED));

            return $document;
        });
    }

    /**
     * @param  array{vehicle_id?: int|string, document_type?: string, document_number?: string|null, issue_date?: string|null, expiry_date?: string|null, remarks?: string|null}  $data
     */
    public function updateMetadata(VehicleDocument $document, array $data, ?int $userId = null): VehicleDocument
    {
        return DB::transaction(function () use ($document, $data, $userId): VehicleDocument {
            // The vehicle is IMMUTABLE: the stored file lives under the owning
            // vehicle's directory, so a silent re-point would orphan the file
            // and break the path-prefix check on every read.
            if (isset($data['vehicle_id']) && (int) $data['vehicle_id'] !== (int) $document->vehicle_id) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'A vehicle document cannot be moved to another vehicle. Upload it on the target vehicle instead.',
                ]);
            }

            $old = $document->only(self::AUDITED);

            $changes = [];
            foreach (['document_type', 'document_number', 'issue_date', 'expiry_date', 'remarks'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = in_array($field, ['document_number', 'remarks'], true)
                        ? ($data[$field] ?: null)
                        : $data[$field];
                }
            }
            $changes['updated_by'] = $userId;

            $document->update($changes);

            $this->audit->record('vehicle_document.updated', $document, $old, $document->only(self::AUDITED));

            return $document;
        });
    }

    /**
     * Soft-delete the record. The stored file is intentionally KEPT so the
     * document history stays retrievable/auditable; the path is snapshotted
     * into the audit entry.
     */
    public function delete(VehicleDocument $document, ?int $userId = null): void
    {
        $snapshot = $document->only(self::AUDITED);

        $document->update(['updated_by' => $userId]);
        $document->delete();

        $this->audit->record('vehicle_document.deleted', $document, $snapshot, []);
    }

    /** Tenant-scoped vehicle lookup: a foreign college's vehicle 404s. */
    private function resolveVehicle(int $collegeId, int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($vehicleId);

        if (! $vehicle) {
            abort(404, 'Vehicle not found in this college context.');
        }

        return $vehicle;
    }

    /**
     * Store an upload under a tenant-scoped private path with a generated name.
     * The original filename is preserved for display only and never influences
     * the stored path.
     */
    private function storeFile(UploadedFile $file, int $collegeId, int $vehicleId): string
    {
        $this->assertAcceptableFile($file);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }
        // Alphanumeric only: strips path separators, dots and null bytes.
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for vehicle documents.',
            ]);
        }

        $directory = sprintf('vehicle-documents/%d/%d', $collegeId, $vehicleId);

        $storedPath = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'private');

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored. Please try again.',
            ]);
        }

        return $storedPath;
    }

    /** Size / MIME limits (the Form Request enforces the same as first line). */
    private function assertAcceptableFile(UploadedFile $file): void
    {
        if ((int) $file->getSize() > self::DEFAULT_MAX_SIZE_KB * 1024) {
            throw ValidationException::withMessages([
                'file' => 'The file exceeds the maximum allowed size of '.self::DEFAULT_MAX_SIZE_KB.' KB.',
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());
        $dangerousMimes = [
            'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/php',
            'text/html', 'application/xhtml+xml', 'image/svg+xml',
            'application/x-executable', 'application/x-sh', 'application/x-shellscript',
            'application/x-msdownload', 'application/x-elf', 'application/x-sharedlib',
        ];

        if (in_array($mime, $dangerousMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for vehicle documents.',
            ]);
        }
    }

    /**
     * Shared guard for every read path: the stored path must be a plain
     * relative private-disk key inside this document's own vehicle directory.
     * Rejects traversal, absolute paths, stream wrappers and null bytes even
     * though the value is server-generated (defence in depth).
     */
    public static function assertSafePath(?string $path, ?int $collegeId = null, ?int $vehicleId = null): string
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

        if ($collegeId !== null && $vehicleId !== null) {
            $prefix = sprintf('vehicle-documents/%d/%d/', $collegeId, $vehicleId);
            if (! str_starts_with($path, $prefix)) {
                abort(403, 'Invalid document path.');
            }
        }

        return $path;
    }

    /**
     * A safe display name for a download response. The name as uploaded stays
     * visible in the module listings but is reduced to one safe segment before
     * it reaches a Content-Disposition header, so a crafted name can neither
     * escape the header nor influence which file is read.
     */
    public static function safeDownloadName(?string $name, string $fallback = 'vehicle-document'): string
    {
        $name = str_replace(['\\', '/'], '', (string) $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\']/', '', $name);
        $name = (string) preg_replace('/[^\x20-\x7E]/', '', $name);
        $name = str_replace(['..', '%'], '', $name);
        $name = trim($name, " \t\n\r\0\x0B.");

        return $name === '' ? $fallback : $name;
    }
}
