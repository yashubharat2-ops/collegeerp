<?php

namespace App\Domain\Student\Services;

use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storage for a student's portrait (the existing `students.photo_path` column).
 *
 * The portrait is a personal image, so it follows the same rules as the rest of
 * the module's private files:
 *
 * - it is written to the tenant-scoped private disk directory
 *   `students/{college_id}/photos`, which is never web-served;
 * - the stored filename is ALWAYS `{uuid}.{whitelisted-extension}`, so nothing
 *   the browser sends can influence the path (no traversal, no scriptable
 *   extension, no collision with another student's file);
 * - the detected MIME type is re-checked here (the form request's `image` rule
 *   is the first line of defence, not the only one);
 * - the old file is deleted only after the replacement has been saved, and a
 *   failed save deletes the new file, so no unreferenced portrait accumulates
 *   and no student loses its picture to a rollback.
 */
class StudentPhotoService
{
    /** Hard ceiling in kilobytes, mirrored by the form request's `max:` rule. */
    public const MAX_KB = 2048;

    /**
     * Extensions the service will persist. Deliberately images only: a portrait
     * can never become a stored document of another kind.
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** MIME types accepted for those extensions. */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly SecureFileService $files,
    ) {}

    /**
     * Store a portrait and return the server-generated path to persist.
     */
    public function store(UploadedFile $file, int $collegeId): string
    {
        $extension = $this->extensionFor($file);

        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages([
                'photo' => 'The photograph may not be larger than '.self::MAX_KB.' KB.',
            ]);
        }

        return $this->files->storeAs(
            $file,
            'students/'.$collegeId.'/photos',
            Str::uuid()->toString().'.'.$extension,
        );
    }

    /**
     * Remove a stored portrait. Path hardening mirrors the controller's read
     * check: only a relative, server-generated path is ever deleted.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return;
        }

        $this->files->delete($path);
    }

    /**
     * Whitelist the extension AND re-check the detected MIME type, because a
     * renamed file (a .jpg that is really a script or an SVG) must never be
     * persisted — even on a private disk.
     */
    private function extensionFor(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());

        if ($extension === 'jpe') {
            $extension = 'jpg';
        }

        $mime = (string) $file->getMimeType();

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true) || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'photo' => 'The photograph must be a JPG, PNG or WebP image.',
            ]);
        }

        return $extension;
    }
}
