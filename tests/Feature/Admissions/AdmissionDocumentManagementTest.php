<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionDocumentManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function makeApplicant($college, array $overrides = []): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'first_name' => 'Doc',
            'last_name' => 'Test',
            'status' => 'active',
        ], $overrides));
    }

    private function makeType($college, string $code = 'ID'): AdmissionDocumentType
    {
        return AdmissionDocumentType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => $code,
            'name' => $code.' Type',
            'status' => 'active',
            'allowed_extensions' => 'pdf,jpg,jpeg,png',
            'allowed_mimes' => 'application/pdf,image/jpeg,image/png',
            'max_size_kb' => 5120,
        ]);
    }

    public function test_admin_can_upload_document_with_private_storage(): void
    {
        $college = $this->makeCollege('DOCM');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);

        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->asCollege($college, $admin)
            ->post(route('admission-documents.store'), [
                'applicant_id' => $applicant->id,
                'document_type_id' => $type->id,
                'file' => $file,
                'remarks' => 'Test upload',
            ], ['Referer' => route('admission-documents.index')])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_documents', [
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'document_type_id' => $type->id,
            'verification_status' => 'pending',
            'original_filename' => 'test.pdf',
        ]);

        $doc = AdmissionDocument::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertNotEquals('test.pdf', $doc->file_path); // never trust original filename
        $this->assertStringStartsWith('admissions/'.$college->id.'/'.$applicant->id.'/', $doc->file_path);
        Storage::disk('private')->assertExists($doc->file_path);
    }

    public function test_server_controlled_fields_cannot_be_forged(): void
    {
        $college = $this->makeCollege('DOCS');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->asCollege($college, $admin)
            ->post(route('admission-documents.store'), [
                'applicant_id' => $applicant->id,
                'document_type_id' => $type->id,
                'file' => $file,
                'college_id' => 999,
                'file_path' => '/etc/passwd',
                'verification_status' => 'verified',
                'verified_by' => 1,
            ], ['Referer' => route('admission-documents.index')])
            ->assertSessionHas('success');

        $doc = AdmissionDocument::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertSame('pending', $doc->verification_status);
        $this->assertNotSame('/etc/passwd', $doc->file_path);
        $this->assertNull($doc->verified_by);
    }

    public function test_validation_requires_applicant_type_and_file(): void
    {
        $college = $this->makeCollege('DOCV');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-documents.store'), [], ['Referer' => route('admission-documents.index')])
            ->assertSessionHasErrors(['applicant_id','document_type_id','file']);

        $this->assertSame(0, AdmissionDocument::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_file_mime_and_size_validation(): void
    {
        $college = $this->makeCollege('DOCF');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);

        $badFile = UploadedFile::fake()->create('bad.exe', 100, 'application/x-msdownload');

        $this->asCollege($college, $admin)
            ->post(route('admission-documents.store'), [
                'applicant_id' => $applicant->id,
                'document_type_id' => $type->id,
                'file' => $badFile,
            ], ['Referer' => route('admission-documents.index')])
            ->assertSessionHasErrors('file');

        $bigFile = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'); // 6MB > 5MB default

        $this->asCollege($college, $admin)
            ->post(route('admission-documents.store'), [
                'applicant_id' => $applicant->id,
                'document_type_id' => $type->id,
                'file' => $bigFile,
            ], ['Referer' => route('admission-documents.index')])
            ->assertSessionHasErrors('file');
    }

    public function test_reupload_resets_verification_and_deletes_old_file(): void
    {
        $college = $this->makeCollege('DOCR');
        // 'create' is required in addition to 'update': the re-upload below
        // posts a new file through the store workflow, so the actor needs both.
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create','admission_documents.update','admission_documents.verify']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);

        $file1 = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
        $this->asCollege($college, $admin)->post(route('admission-documents.store'), [
            'applicant_id' => $applicant->id,
            'document_type_id' => $type->id,
            'file' => $file1,
        ], ['Referer' => route('admission-documents.index')]);

        $doc = AdmissionDocument::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $oldPath = $doc->file_path;
        Storage::disk('private')->assertExists($oldPath);

        // Verify it
        $this->asCollege($college, $admin)->post(route('admission-documents.verify', $doc), [
            'action' => 'verify',
        ], ['Referer' => route('admission-documents.index')])->assertSessionHas('success');

        $doc->refresh();
        $this->assertSame('verified', $doc->verification_status);
        $this->assertNotNull($doc->verified_by);

        // Re-upload
        $file2 = UploadedFile::fake()->create('second.pdf', 100, 'application/pdf');
        $this->asCollege($college, $admin)->put(route('admission-documents.update', $doc), [
            'file' => $file2,
            'remarks' => 'Re-upload',
        ], ['Referer' => route('admission-documents.edit', $doc)])->assertSessionHas('success');

        $doc->refresh();
        $this->assertSame('pending', $doc->verification_status);
        $this->assertNull($doc->verified_by);
        $this->assertNull($doc->verified_at);
        $this->assertSame('second.pdf', $doc->original_filename);
        Storage::disk('private')->assertExists($doc->file_path);
        Storage::disk('private')->assertMissing($oldPath);
    }

    public function test_verify_and_reject_workflow_with_audit(): void
    {
        $college = $this->makeCollege('DOCW');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create','admission_documents.verify']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->asCollege($college, $admin)->post(route('admission-documents.store'), [
            'applicant_id' => $applicant->id,
            'document_type_id' => $type->id,
            'file' => $file,
        ], ['Referer' => route('admission-documents.index')]);

        $doc = AdmissionDocument::withoutGlobalScopes()->firstWhere('college_id', $college->id);

        // Reject
        $this->asCollege($college, $admin)->post(route('admission-documents.verify', $doc), [
            'action' => 'reject',
            'rejection_remarks' => 'Blurry image',
        ], ['Referer' => route('admission-documents.index')])->assertSessionHas('success');

        $doc->refresh();
        $this->assertSame('rejected', $doc->verification_status);
        $this->assertSame('Blurry image', $doc->rejection_remarks);

        $this->assertDatabaseHas('audit_logs', [
            'college_id' => $college->id,
            'action' => 'admission_document.rejected',
            'subject_type' => AdmissionDocument::class,
            'subject_id' => $doc->id,
        ]);

        // Verify after rejection
        $this->asCollege($college, $admin)->post(route('admission-documents.verify', $doc), [
            'action' => 'verify',
        ], ['Referer' => route('admission-documents.index')])->assertSessionHas('success');

        $doc->refresh();
        $this->assertSame('verified', $doc->verification_status);
        $this->assertNull($doc->rejection_remarks);
    }

    public function test_download_requires_permission_and_tenant_scope(): void
    {
        $college = $this->makeCollege('DOCD');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);
        $doc = AdmissionDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'document_type_id' => $type->id,
            'file_path' => 'admissions/'.$college->id.'/'.$applicant->id.'/fake.pdf',
            'original_filename' => 'fake.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'verification_status' => 'pending',
        ]);
        Storage::disk('private')->put($doc->file_path, 'fake content');

        $this->asCollege($college, $admin)->get(route('admission-documents.download', $doc))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'college_id' => $college->id,
            'action' => 'admission_document.downloaded',
            'subject_id' => $doc->id,
        ]);
    }

    public function test_private_file_not_publicly_accessible_and_path_traversal_prevented(): void
    {
        $college = $this->makeCollege('DOCP');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view']);
        $applicant = $this->makeApplicant($college);
        $type = $this->makeType($college);
        // Simulate path traversal attempt in DB (should be blocked on download)
        $doc = AdmissionDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'document_type_id' => $type->id,
            'file_path' => '../../etc/passwd',
            'original_filename' => 'passwd',
            'file_size' => 100,
            'verification_status' => 'pending',
        ]);

        $this->asCollege($college, $admin)->get(route('admission-documents.download', $doc))->assertStatus(403);

        // The private download controller rejects the traversal path with 403
        // before it ever reaches storage. league/flysystem 3.x additionally
        // throws PathTraversalDetected for any traversal string it is asked to
        // resolve, so a remaining regression manifests as an exception rather
        // than a boolean false — both are acceptable as "blocked".
        try {
            $this->assertFalse(Storage::disk('public')->exists($doc->file_path));
        } catch (\League\Flysystem\PathTraversalDetected) {
            $this->addToAssertionCount(1); // traversal path blocked at the filesystem layer
        }
    }

    public function test_index_filtering_and_deterministic_ordering(): void
    {
        $college = $this->makeCollege('DOCI');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view']);
        $applicant = $this->makeApplicant($college);
        $type1 = $this->makeType($college, 'ID');
        $type2 = $this->makeType($college, 'MARK');

        foreach (range(1,16) as $i) {
            AdmissionDocument::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'applicant_id' => $applicant->id,
                'document_type_id' => $i <= 8 ? $type1->id : $type2->id,
                'file_path' => 'admissions/'.$college->id.'/'.$applicant->id.'/file'.$i.'.pdf',
                'original_filename' => 'file'.$i.'.pdf',
                'file_size' => 100,
                'verification_status' => $i % 2 === 0 ? 'verified' : 'pending',
            ]);
        }

        // Pagination deterministic: newest first with id tiebreak
        $this->asCollege($college, $admin)->get(route('admission-documents.index'))
            ->assertSee('file16.pdf')->assertDontSee('file1.pdf');

        $this->asCollege($college, $admin)->get(route('admission-documents.index', ['verification_status'=>'verified']))
            ->assertSee('file2.pdf')->assertDontSee('file1.pdf');

        $this->asCollege($college, $admin)->get(route('admission-documents.index', ['document_type_id'=>$type1->id]))
            ->assertSee('file1.pdf')->assertDontSee('file9.pdf');
    }
}
