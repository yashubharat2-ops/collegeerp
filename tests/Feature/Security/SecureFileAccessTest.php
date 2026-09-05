<?php
namespace Tests\Feature\Security;
use App\Services\Files\SecureFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class SecureFileAccessTest extends TestCase
{
    public function test_files_are_stored_on_private_disk(): void
    {
        Storage::fake('private');
        $path = app(SecureFileService::class)->store(UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'), 'documents');
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
}
