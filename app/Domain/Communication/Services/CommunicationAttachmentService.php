<?php

namespace App\Domain\Communication\Services;

use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure storage boundary for Notice and Circular attachments
 * (Communication Management, Phase 1).
 *
 * Follows the project's private-document pattern (student / employee /
 * vehicle documents):
 *
 *  - Files live on the non-public `private` disk and are only ever streamed
 *    through an authorized controller action — never exposed by URL.
 *  - The stored key is ALWAYS generated server-side as
 *    `communication/{area}/{college_id}/{uuid}.{ext}`. A user-supplied path or
 *    filename never influences where a file is written or which file is read,
 *    so path traversal is impossible by construction.
 *  - Every read/delete re-validates the stored key (defence in depth against
 *    a tampered database value) and pins it to the record's own college and
 *    area prefix.
 *  - The original filename is kept for display only and is reduced to one
 *    safe segment before it reaches a Content-Disposition header.
 *  - Size / type limits come from config/communication.php and are enforced
 *    here as well as in the Form Requests (server-side is authoritative);
 *    executable and scriptable types are always refused.
 */
class CommunicationAttachmentService
{
    public const AREA_NOTICES = 'notices';

    public const AREA_CIRCULARS = 'circulars';

    private const AREAS = [self::AREA_NOTICES, self::AREA_CIRCULARS];

    private const ROOT = 'communication';

    private const DISK = 'private';

    /** Never accepted, whatever the configured allow-list says. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb', 'sh',
        'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'so', 'msi', 'scr', 'vbs',
        'js', 'mjs', 'jar', 'htm', 'html', 'shtml', 'svg', 'svgz', 'xhtml', 'xml', 'hta',
    ];

    private const BLOCKED_MIMES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/php',
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/javascript', 'application/javascript',
        'application/x-executable', 'application/x-sh', 'application/x-shellscript',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-elf', 'application/x-sharedlib',
    ];

    public function __construct(private readonly SecureFileService $files) {}

    public static function maxKilobytes(): int
    {
        return max(1, (int) config('communication.attachments.max_kb', 5120));
    }

    /**
     * @return array<int, string>
     */
    public static function allowedExtensions(): array
    {
        $configured = (array) config('communication.attachments.mimes', ['pdf', 'jpg', 'jpeg', 'png']);
        $clean = array_map(fn ($ext) => strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $ext)), $configured);

        return array_values(array_diff(array_filter(array_unique($clean)), self::BLOCKED_EXTENSIONS));
    }

    /**
     * Form Request rules for an optional attachment upload.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'file', 'max:'.self::maxKilobytes(), 'mimes:'.implode(',', self::allowedExtensions())];
    }

    /**
     * Store an upload under the tenant-scoped private directory of an area.
     *
     * @return array{path: string, name: string, mime: string|null, size: int}
     */
    public function store(UploadedFile $file, string $area, int $collegeId): array
    {
        $this->assertArea($area);

        if ($collegeId <= 0) {
            throw new InvalidArgumentException('A college id is required to store an attachment.');
        }

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['attachment' => 'The attachment failed to upload. Please try again.']);
        }

        if ((int) $file->getSize() > self::maxKilobytes() * 1024) {
            throw ValidationException::withMessages([
                'attachment' => 'The attachment may not be larger than '.self::maxKilobytes().' KB.',
            ]);
        }

        if (in_array(strtolower((string) $file->getMimeType()), self::BLOCKED_MIMES, true)) {
            throw ValidationException::withMessages(['attachment' => 'This file type is not allowed.']);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = strtolower((string) $file->extension());
        }
        // Alphanumeric only: strips separators, dots and null bytes.
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === ''
            || in_array($extension, self::BLOCKED_EXTENSIONS, true)
            || ! in_array($extension, self::allowedExtensions(), true)
        ) {
            throw ValidationException::withMessages(['attachment' => 'This file type is not allowed.']);
        }

        $path = $file->storeAs(self::directory($area, $collegeId), Str::uuid()->toString().'.'.$extension, self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['attachment' => 'The attachment could not be stored. Please try again.']);
        }

        return [
            'path' => $path,
            'name' => self::displayName($file->getClientOriginalName(), 'attachment.'.$extension),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * Delete a stored attachment. The key is re-validated first, so a
     * tampered value can never become an arbitrary filesystem path.
     */
    public function delete(?string $path, string $area, int $collegeId): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->files->delete(self::assertSafePath($path, $area, $collegeId));
    }

    /**
     * Stream an attachment as a download (after the caller has authorized).
     */
    public function download(?string $path, string $area, int $collegeId, ?string $name, string $fallback): StreamedResponse
    {
        $safe = self::assertSafePath($path, $area, $collegeId);

        if (! Storage::disk(self::DISK)->exists($safe)) {
            abort(404, 'The attachment is no longer available.');
        }

        // The sanitised name must keep the stored file's (validated) extension;
        // otherwise (e.g. a fully non-ASCII original name) use the fallback.
        $extension = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        $downloadName = self::safeDownloadName($name, $fallback);

        if ($extension !== '' && ! str_ends_with(strtolower($downloadName), '.'.$extension)) {
            $downloadName = self::safeDownloadName($fallback, 'attachment').'.'.$extension;
        }

        return $this->files->download($safe, $downloadName);
    }

    /**
     * Shared guard for every read/delete path: the key must be a plain
     * relative private-disk key directly inside this record's own
     * `communication/{area}/{college_id}/` directory, with a generated file
     * name. Rejects traversal, absolute paths, stream wrappers, backslashes and
     * null bytes (defence in depth — keys are server-generated).
     */
    public static function assertSafePath(?string $path, string $area, int $collegeId): string
    {
        $path = (string) $path;
        $prefix = self::directory($area, $collegeId).'/';

        if ($path === ''
            || ! in_array($area, self::AREAS, true)
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('#^[a-z0-9.+-]+:#i', $path)
            || ! str_starts_with($path, $prefix)
            || ! preg_match('/^[A-Za-z0-9-]+\.[a-z0-9]+$/', substr($path, strlen($prefix)))
        ) {
            abort(403, 'Invalid attachment path.');
        }

        return $path;
    }

    /**
     * A safe Content-Disposition filename derived from the original name.
     */
    public static function safeDownloadName(?string $name, string $fallback = 'attachment'): string
    {
        $name = str_replace(['\\', '/'], '', (string) $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\']/', '', $name);
        $name = (string) preg_replace('/[^\x20-\x7E]/', '', $name);
        $name = str_replace(['..', '%'], '', $name);
        $name = trim($name, " \t\n\r\0\x0B.");

        return $name === '' ? $fallback : Str::limit($name, 150, '');
    }

    /** Display name kept on the record: control characters removed, bounded length. */
    private static function displayName(?string $name, string $fallback): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name);
        $name = trim(str_replace(['\\', '/'], '-', $name));

        return $name === '' ? $fallback : mb_substr($name, 0, 255);
    }

    private static function directory(string $area, int $collegeId): string
    {
        return sprintf('%s/%s/%d', self::ROOT, $area, $collegeId);
    }

    private function assertArea(string $area): void
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new InvalidArgumentException("Unknown attachment area [{$area}].");
        }
    }
}
