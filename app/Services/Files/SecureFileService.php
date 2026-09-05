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
    public function download(string $path) { return Storage::disk('private')->download($path); }
    public function delete(string $path): void { Storage::disk('private')->delete($path); }
}
