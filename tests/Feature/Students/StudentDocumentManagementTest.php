<?php

namespace Tests\Feature\Students;

use App\Models\StudentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Student documents: secure upload, validation, verification, replacement and
 * deletion, plus the file-handling guarantees (server-generated path, private
 * disk, history preserved).
 */
class StudentDocumentManagementTest extends TestCase
{
    use StudentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function payload(int $studentId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'title' => 'Class XII Marksheet',
            'issue_date' => '2026-04-01',
            'expiry_date' => '2029-04-01',
            'remarks' => 'Scanned copy',
        ], $overrides);
    }

    public function test_authorized_user_can_view_index(): void
    {
        $college = $this->makeCollege('DOCIDX');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.view']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-DOC-1']);

        $this->asCollege($college, $admin)
            ->get(route('student-documents.index', ['student_id' => $student->id]))
            ->assertOk()
            ->assertSee('Student Documents');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-documents.index'))->assertRedirect(route('login'));
    }

    public function test_each_permission_is_required_separately(): void
    {
        $college = $this->makeCollege('DOCPERM');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);

        $this->asCollege($college, $viewer)->get(route('student-documents.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-documents.create'))->assertForbidden();
        $this->asCollege($college, $viewer)
            ->post(route('student-documents.store'), $this->payload($student->id, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]))
            ->assertForbidden();
    }

    public function test_upload_stores_file_on_private_disk_with_generated_path(): void
    {
        $college = $this->makeCollege('DOCUP');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.view', 'student_documents.create']);
        $student = $this->makeStudent($college);
        $type = $this->makeDocumentType($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'document_type_id' => $type->id,
                'file' => UploadedFile::fake()->create('my marksheet (final).pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-documents.index'));

        $document = StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertNotNull($document);
        $this->assertSame($student->id, $document->student_id);
        $this->assertSame($type->id, $document->document_type_id);
        $this->assertSame('Class XII Marksheet', $document->title);
        $this->assertSame('pending', $document->verification_status);
        $this->assertSame('my marksheet (final).pdf', $document->original_filename);
        $this->assertSame($college->id, $document->college_id);

        // Path is tenant-scoped and server-generated: never the original name.
        $this->assertStringStartsWith(sprintf('students/%d/%d/', $college->id, $student->id), $document->file_path);
        $this->assertStringNotContainsString('marksheet', $document->file_path);
        $this->assertStringEndsWith('.pdf', $document->file_path);
        $this->assertMatchesRegularExpression('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/', $document->file_path);

        Storage::disk('private')->assertExists($document->file_path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_document.uploaded', 'college_id' => $college->id]);
    }

    public function test_file_is_required_and_type_is_validated(): void
    {
        $college = $this->makeCollege('DOCVAL');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.create']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id))
            ->assertSessionHasErrors('file');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'title' => 'Script',
                'file' => UploadedFile::fake()->create('shell.php', 10, 'text/x-php'),
            ]))
            ->assertSessionHasErrors('file');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('file');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'title' => '',
                'file' => UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('title');

        $this->assertSame(0, StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_document_type_configuration_limits_extensions(): void
    {
        $college = $this->makeCollege('DOCTYPE');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.create']);
        $student = $this->makeStudent($college);
        $pdfOnly = $this->makeDocumentType($college, 'PDFONLY', 5120, 'pdf');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'document_type_id' => $pdfOnly->id,
                'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
            ]))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The same file is accepted once a type that allows it is used.
        $images = $this->makeDocumentType($college, 'IMAGES', 5120, 'jpg,jpeg,png');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'document_type_id' => $images->id,
                'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_replacing_the_file_resets_verification_and_removes_the_old_blob(): void
    {
        $college = $this->makeCollege('DOCREP');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.view', 'student_documents.create', 'student_documents.update']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, ['file' => UploadedFile::fake()->create('first.pdf', 30, 'application/pdf')]))
            ->assertSessionHasNoErrors();

        $document = StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $firstPath = $document->file_path;

        $this->asCollege($college, $admin)
            ->post(route('student-documents.verify', $document), ['action' => 'verify'])
            ->assertSessionHas('success');
        $this->assertSame('verified', $document->fresh()->verification_status);

        $this->asCollege($college, $admin)
            ->put(route('student-documents.update', $document), [
                'title' => 'Class XII Marksheet',
                'file' => UploadedFile::fake()->create('second.pdf', 40, 'application/pdf'),
            ], ['Referer' => route('student-documents.edit', $document)])
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame('pending', $document->verification_status, 'A replacement must not inherit verification.');
        $this->assertNull($document->verified_by);
        $this->assertNull($document->verified_at);
        $this->assertSame('second.pdf', $document->original_filename);
        $this->assertNotSame($firstPath, $document->file_path);

        Storage::disk('private')->assertExists($document->file_path);
        Storage::disk('private')->assertMissing($firstPath);

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_document.replaced']);
    }

    public function test_metadata_update_keeps_the_file_and_verification(): void
    {
        $college = $this->makeCollege('DOCMETA');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.create', 'student_documents.update']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, ['file' => UploadedFile::fake()->create('keep.pdf', 30, 'application/pdf')]))
            ->assertSessionHasNoErrors();

        $document = StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $path = $document->file_path;

        $this->asCollege($college, $admin)
            ->put(route('student-documents.update', $document), [
                'title' => 'Renamed title',
                'remarks' => 'Updated remarks',
            ], ['Referer' => route('student-documents.edit', $document)])
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame('Renamed title', $document->title);
        $this->assertSame('Updated remarks', $document->remarks);
        $this->assertSame($path, $document->file_path);
        $this->assertSame('pending', $document->verification_status);
    }

    public function test_verify_and_reject_workflow(): void
    {
        $college = $this->makeCollege('DOCVER');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.create', 'student_documents.update']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, ['file' => UploadedFile::fake()->create('v.pdf', 10, 'application/pdf')]))
            ->assertSessionHasNoErrors();

        $document = StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)
            ->post(route('student-documents.verify', $document), ['action' => 'reject'])
            ->assertSessionHasErrors('rejection_remarks');

        $this->asCollege($college, $admin)
            ->post(route('student-documents.verify', $document), ['action' => 'reject', 'rejection_remarks' => 'Illegible scan'])
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame('rejected', $document->verification_status);
        $this->assertSame('Illegible scan', $document->rejection_remarks);
        $this->assertSame($admin->id, $document->verified_by);
        $this->assertNotNull($document->verified_at);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.verify', $document), ['action' => 'verify'])
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame('verified', $document->verification_status);
        $this->assertNull($document->rejection_remarks);
    }

    public function test_delete_soft_deletes_the_record_and_keeps_the_file(): void
    {
        $college = $this->makeCollege('DOCDEL');
        $admin = $this->makeUserWithPermissions($college, ['student_documents.create', 'student_documents.delete']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-documents.store'), $this->payload($student->id, ['file' => UploadedFile::fake()->create('history.pdf', 10, 'application/pdf')]))
            ->assertSessionHasNoErrors();

        $document = StudentDocument::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $path = $document->file_path;

        $this->asCollege($college, $admin)
            ->delete(route('student-documents.destroy', $document), [], ['Referer' => route('student-documents.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('student_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('student_documents', ['id' => $document->id, 'file_path' => $path]);
        Storage::disk('private')->assertExists($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_document.deleted']);
    }

    public function test_college_id_and_verification_state_from_browser_are_never_trusted(): void
    {
        $collegeA = $this->makeCollege('DOCSAFE');
        $collegeB = $this->makeCollege('DOCSAFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_documents.create']);
        $student = $this->makeStudent($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-documents.store'), $this->payload($student->id, [
                'college_id' => $collegeB->id,
                'verification_status' => 'verified',
                'verified_by' => $adminA->id,
                'file_path' => 'students/hacked/path.pdf',
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $document = StudentDocument::withoutGlobalScopes()->where('student_id', $student->id)->first();

        $this->assertSame($collegeA->id, $document->college_id);
        $this->assertSame('pending', $document->verification_status);
        $this->assertNull($document->verified_by);
        $this->assertNotSame('students/hacked/path.pdf', $document->file_path);
        $this->assertSame(0, StudentDocument::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }
}
