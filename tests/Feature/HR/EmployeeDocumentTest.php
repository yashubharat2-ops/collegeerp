<?php

namespace Tests\Feature\HR;

use App\Models\{EmployeeDocument, Faculty};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use HRTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_employee_document_upload_download_and_delete_are_audited(): void
    {
        $college = $this->makeCollege('HRDOC');
        $admin = $this->makeUserWithPermissions($college, [
            'faculties.view', 'employee_documents.view', 'employee_documents.create',
            'employee_documents.update', 'employee_documents.delete',
        ]);
        $employee = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'EMP-DOC',
            'first_name' => 'Document',
            'last_name' => 'Owner',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('employee-documents.store'), [
                'employee_id' => $employee->id,
                'document_name' => 'Employment Contract',
                'document_type' => 'Contract',
                'issue_date' => '2026-01-01',
                'expiry_date' => '2027-01-01',
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ], ['Referer' => route('employee-documents.index')])
            ->assertRedirect(route('employee-documents.index'))
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::withoutGlobalScopes()->where('faculty_id', $employee->id)->firstOrFail();
        $this->assertStringStartsWith('employee-documents/'.$college->id.'/'.$employee->id.'/', $document->file_path);
        $this->assertStringNotContainsString('contract.pdf', $document->file_path);
        Storage::disk('private')->assertExists($document->file_path);

        $response = $this->asCollege($college, $admin)->get(route('employee-documents.download', $document));
        $response->assertOk()->assertHeader('content-disposition', 'attachment; filename=contract.pdf');

        $this->asCollege($college, $admin)
            ->delete(route('employee-documents.destroy', $document), [], ['Referer' => route('employee-documents.index')])
            ->assertRedirect(route('employee-documents.index'));
        $this->assertSoftDeleted('employee_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_document.uploaded', 'college_id' => $college->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_document.downloaded', 'college_id' => $college->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_document.deleted', 'college_id' => $college->id]);
    }

    public function test_employee_documents_are_tenant_and_permission_safe(): void
    {
        $collegeA = $this->makeCollege('HRDOCA');
        $collegeB = $this->makeCollege('HRDOCB');
        $employeeB = Faculty::create([
            'college_id' => $collegeB->id,
            'employee_code' => 'EMP-B',
            'first_name' => 'Foreign',
            'last_name' => 'Employee',
            'status' => 'active',
        ]);
        $document = EmployeeDocument::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'faculty_id' => $employeeB->id,
            'document_name' => 'Private file',
            'document_type' => 'ID',
            'file_path' => 'employee-documents/'.$collegeB->id.'/'.$employeeB->id.'/private.pdf',
            'original_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);
        Storage::disk('private')->put($document->file_path, 'private-bytes');

        $viewerA = $this->makeUserWithPermissions($collegeA, ['employee_documents.view', 'employee_documents.update', 'employee_documents.delete']);
        $this->asCollege($collegeA, $viewerA)->get(route('employee-documents.show', $document))->assertNotFound();
        $this->asCollege($collegeA, $viewerA)->get(route('employee-documents.download', $document))->assertNotFound();
        $this->asCollege($collegeA, $viewerA)->delete(route('employee-documents.destroy', $document))->assertNotFound();

        $noView = $this->makeUserWithPermissions($collegeB, []);
        $this->asCollege($collegeB, $noView)->get(route('employee-documents.download', $document))->assertForbidden();
    }
}
