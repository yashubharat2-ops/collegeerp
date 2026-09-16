<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionDocumentTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_list_only_shows_documents_of_active_college(): void
    {
        $collegeA = $this->makeCollege('TDCA');
        $collegeB = $this->makeCollege('TDCB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_documents.view']);

        $appA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'A', 'status' => 'active']);
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);

        $typeA = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'code' => 'ID', 'name' => 'ID', 'status' => 'active', 'max_size_kb' => 5120]);
        $typeB = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'code' => 'ID', 'name' => 'ID', 'status' => 'active', 'max_size_kb' => 5120]);

        AdmissionDocument::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $appA->id, 'document_type_id' => $typeA->id, 'file_path' => 'a.pdf', 'original_filename' => 'a.pdf', 'file_size' => 100, 'verification_status' => 'pending']);
        AdmissionDocument::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'document_type_id' => $typeB->id, 'file_path' => 'b.pdf', 'original_filename' => 'b.pdf', 'file_size' => 100, 'verification_status' => 'pending']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-documents.index'))->assertSee('a.pdf')->assertDontSee('b.pdf');
    }

    public function test_cross_college_document_cannot_be_accessed_or_verified(): void
    {
        $collegeA = $this->makeCollege('TDCX');
        $collegeB = $this->makeCollege('TDCY');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);
        $typeB = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'code' => 'ID', 'name' => 'ID', 'status' => 'active', 'max_size_kb' => 5120]);
        $docB = AdmissionDocument::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'document_type_id' => $typeB->id, 'file_path' => 'b.pdf', 'original_filename' => 'b.pdf', 'file_size' => 100, 'verification_status' => 'pending']);
        Storage::disk('private')->put('b.pdf', 'content');

        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_documents.view','admission_documents.update','admission_documents.delete','admission_documents.verify']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-documents.edit', $docB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->get(route('admission-documents.download', $docB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->post(route('admission-documents.verify', $docB), ['action'=>'verify'])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admission-documents.destroy', $docB))->assertNotFound();

        $this->assertDatabaseHas('admission_documents', ['id'=>$docB->id]);
    }

    public function test_applicant_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('TDCT');
        $collegeB = $this->makeCollege('TDCU');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);
        $typeA = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'code' => 'ID', 'name' => 'ID', 'status' => 'active', 'max_size_kb' => 5120]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_documents.view','admission_documents.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-documents.store'), [
                'applicant_id' => $appB->id,
                'document_type_id' => $typeA->id,
                'file' => \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'),
            ], ['Referer'=>route('admission-documents.index')])
            ->assertSessionHasErrors('applicant_id');
    }

    public function test_cross_college_file_access_is_prevented(): void
    {
        $collegeA = $this->makeCollege('TDCF');
        $collegeB = $this->makeCollege('TDCZ');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);
        $typeB = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'code' => 'ID', 'name' => 'ID', 'status' => 'active', 'max_size_kb' => 5120]);
        $docB = AdmissionDocument::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'document_type_id' => $typeB->id, 'file_path' => 'admissions/'.$collegeB->id.'/'.$appB->id.'/secret.pdf', 'original_filename' => 'secret.pdf', 'file_size' => 100, 'verification_status' => 'pending']);
        Storage::disk('private')->put($docB->file_path, 'secret');

        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_documents.view']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-documents.download', $docB))->assertNotFound();
    }
}
