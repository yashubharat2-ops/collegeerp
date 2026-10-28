<?php

namespace App\Domain\Student\Services;

use App\Models\AdmissionDocumentType;
use App\Models\StudentRegistrationDraft;
use App\Models\StudentRegistrationDraftDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Documents uploaded while the wizard has no student yet.
 *
 * Same rules as the Documents module, applied before a student exists:
 *
 * - the file lands on the `private` disk under
 *   `students/{college_id}/registration-drafts/{draft_id}/{uuid}.{ext}` — a
 *   server-generated path, so nothing the browser sends can influence where the
 *   file is written;
 * - the extension is reduced to [a-z0-9] and checked against the document
 *   type's configuration (falling back to the safe default set) and against the
 *   module's blocked-extension list;
 * - size is capped by the lower of the type configuration and the registration
 *   attachment ceiling (1 MB), and executable content types are refused.
 *
 * On submission {@see RegistrationSubmissionService} ADOPTS these rows into
 * `student_documents` (the same blob, the same architecture) instead of
 * re-uploading or copying the file.
 */
class RegistrationDocumentStagingService
{
    public const DEFAULT_MAX_KB = 1024;

    public function __construct(
        private readonly SecureFileService $files,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Store a new staged document for the draft.
     *
     * @param  array{title?: string|null, document_type_id?: mixed, remarks?: string|null}  $meta
     */
    public function store(
        StudentRegistrationDraft $draft,
        UploadedFile $file,
        array $meta,
        int $collegeId,
        ?int $userId = null,
    ): StudentRegistrationDraftDocument {
        $type = $this->resolveType($meta['document_type_id'] ?? null, $collegeId);
        $storedPath = $this->storeFile($file, $type, $collegeId, (int) $draft->id);
        $title = $this->title($meta['title'] ?? null, $type, $file);

        try {
            return DB::transaction(function () use ($draft, $file, $type, $storedPath, $title, $meta, $collegeId, $userId): StudentRegistrationDraftDocument {
                $document = StudentRegistrationDraftDocument::create([
                    'college_id' => $collegeId,
                    'draft_id' => $draft->id,
                    'document_type_id' => $type?->id,
                    'title' => $title,
                    'file_path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'remarks' => $meta['remarks'] ?? null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $this->audit->record('student.registration_document_staged', $document, [], [
                    'id' => $document->id,
                    'draft_id' => $draft->id,
                    'document_type_id' => $type?->id,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            // The row was rolled back: do not leave an unreferenced blob behind.
            $this->files->delete($storedPath);
            throw $e;
        }
    }

    /**
     * Replace the file of a staged document. The metadata (type, title) is
     * re-validated, and the superseded blob is removed only after the new one is
     * committed.
     */
    public function replace(
        StudentRegistrationDraftDocument $document,
        UploadedFile $file,
        array $meta,
        int $collegeId,
        ?int $userId = null,
    ): StudentRegistrationDraftDocument {
        $draft = $document->draft;

        if ($draft === null || $draft->isSubmitted()) {
            throw ValidationException::withMessages([
                'file' => 'This registration has already been submitted; attach the file from the Documents module instead.',
            ]);
        }

        $type = $this->resolveType(
            array_key_exists('document_type_id', $meta) ? $meta['document_type_id'] : $document->document_type_id,
            $collegeId,
        );

        $oldPath = $document->file_path;
        $storedPath = $this->storeFile($file, $type, $collegeId, (int) $draft->id);

        try {
            DB::transaction(function () use ($document, $file, $type, $storedPath, $meta, $userId): void {
                $document->update([
                    'document_type_id' => $type?->id,
                    'title' => $this->title($meta['title'] ?? null, $type, $file),
                    'file_path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'remarks' => $meta['remarks'] ?? $document->remarks,
                    'updated_by' => $userId,
                ]);

                $this->audit->record('student.registration_document_replaced', $document, [], [
                    'id' => $document->id,
                    'original_filename' => $document->original_filename,
                ]);
            });
        } catch (\Throwable $e) {
            $this->files->delete($storedPath);
            throw $e;
        }

        if ($oldPath && $oldPath !== $storedPath) {
            $this->files->delete($oldPath);
        }

        return $document->refresh();
    }

    /** Remove a staged document and its blob. */
    public function delete(StudentRegistrationDraftDocument $document): void
    {
        $path = $document->file_path;
        $snapshot = ['id' => $document->id, 'draft_id' => $document->draft_id];

        DB::transaction(function () use ($document, $snapshot): void {
            $document->delete();
            $this->audit->record('student.registration_document_removed', null, $snapshot, []);
        });

        if (is_string($path) && $path !== '') {
            StudentDocumentService::assertSafePath($path);
            $this->files->delete($path);
        }
    }

    /** The private-disk path of a staged document, checked before it is read. */
    public function path(StudentRegistrationDraftDocument $document, int $collegeId): string
    {
        $path = StudentDocumentService::assertSafePath($document->file_path);

        // Defence in depth: a row may only ever point inside this college's
        // directory, even though the path is server-generated.
        if (! str_starts_with($path, 'students/'.$collegeId.'/')) {
            abort(403, 'Invalid document path.');
        }

        return $path;
    }

    /**
     * @param  array<int, StudentRegistrationDraftDocument>  $documents
     */
    public function deleteAll(array $documents): void
    {
        foreach ($documents as $document) {
            $this->delete($document);
        }
    }

    private function resolveType(mixed $typeId, int $collegeId): ?AdmissionDocumentType
    {
        if ($typeId === null || $typeId === '') {
            return null;
        }

        // Tenant-scoped lookup: another college's type is a 404, not a match.
        $type = AdmissionDocumentType::query()
            ->where('college_id', $collegeId)
            ->whereKey((int) $typeId)
            ->first();

        if ($type === null) {
            throw ValidationException::withMessages([
                'document_type_id' => 'Choose a document type that belongs to this college.',
            ]);
        }

        return $type;
    }

    private function title(?string $title, ?AdmissionDocumentType $type, UploadedFile $file): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        return $type?->name ?: mb_substr($file->getClientOriginalName(), 0, 255);
    }

    /** Persist the upload under a tenant-scoped, generated private path. */
    private function storeFile(UploadedFile $file, ?AdmissionDocumentType $type, int $collegeId, int $draftId): string
    {
        $this->assertAcceptableFile($file, $type);

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }

        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === '' || in_array($extension, StudentDocumentService::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for registration documents.',
            ]);
        }

        $path = $file->storeAs(
            sprintf('students/%d/registration-drafts/%d', $collegeId, $draftId),
            Str::uuid()->toString().'.'.$extension,
            'private',
        );

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored. Please try again.',
            ]);
        }

        return $path;
    }

    /** Size / extension / content-type limits from the document type config. */
    private function assertAcceptableFile(UploadedFile $file, ?AdmissionDocumentType $type): void
    {
        // Registration attachments have a hard 1 MB ceiling, even when a
        // document type is configured with a larger limit. Lower type limits
        // remain in force.
        $configuredMaxKb = (int) ($type?->max_size_kb ?: self::DEFAULT_MAX_KB);
        $maxKb = min($configuredMaxKb, self::DEFAULT_MAX_KB);

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

        if ($extension === 'jpe') {
            $extension = 'jpg';
        }

        if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file extension .'.$extension.' is not allowed for '.($type->name ?? 'this document').'. Allowed: '.implode(', ', $allowed).'.',
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());

        if (in_array($mime, StudentDocumentService::DANGEROUS_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed for registration documents.',
            ]);
        }
    }
}
