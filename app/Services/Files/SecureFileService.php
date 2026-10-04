<?php
namespace App\Services\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
class SecureFileService
{
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, 'private');
    }

    /**
     * Store an upload under a caller-supplied, server-generated filename.
     *
     * The name is never taken from the request: callers pass a uuid plus a
     * whitelisted extension, which is how a photo or document ends up with a
     * path the client cannot influence. `$directory` is likewise built by the
     * caller from server-side values (college id, student id).
     */
    public function storeAs(UploadedFile $file, string $directory, string $filename): string
    {
        return $file->storeAs($directory, $filename, 'private');
    }

    /**
     * Stream a private-disk file as a download.
     *
     * $name only sets the Content-Disposition filename shown by the browser; it
     * never influences which file is read (the stored $path does), so a
     * user-supplied original filename cannot redirect the download.
     */
    public function download(string $path, ?string $name = null)
    {
        return Storage::disk('private')->download($path, $name);
    }

    /**
     * Stream a private-disk file INLINE.
     *
     * Used for content the browser must render in place — a student's portrait
     * in <img> on the profile and the ID card. `download()` sends
     * `Content-Disposition: attachment`, which makes browsers refuse to render
     * such an image, so the two cases are kept distinct on purpose. The file
     * still lives outside the web root and is only ever served through an
     * authorized controller action.
     */
    public function inline(string $path)
    {
        return Storage::disk('private')->response($path);
    }

    public function delete(string $path): void { Storage::disk('private')->delete($path); }
}
