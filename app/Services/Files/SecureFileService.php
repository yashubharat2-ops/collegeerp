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
     * Stream a private-disk file as a download.
     *
     * $name only sets the Content-Disposition filename shown to the browser; it
     * never influences which file is read (the stored $path does), so a
     * user-supplied original filename cannot redirect the download.
     */
    public function download(string $path, ?string $name = null)
    {
        return Storage::disk('private')->download($path, $name);
    }

    public function delete(string $path): void { Storage::disk('private')->delete($path); }
}
