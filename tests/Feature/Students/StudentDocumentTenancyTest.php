<?php

namespace Tests\Feature\Students;

use App\Models\StudentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tenant isolation and safe-file-access guarantees for student documents.
 *
 * Covers URL/ID manipulation, cross-college downloads, unauthorized downloads,
 * and a tampered stored path (path traversal) — all of which must fail closed.
 */
class StudentDocumentTenancyTest extends TestCase
{
    use StudentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function upload(int $collegeId, int $studentId): StudentDocument
    {
        return StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $collegeId,
            'student_id' => $studentId,
            'title' => 'Transfer Certificate',
            'file_path' => sprintf('students/%d/%d/%s.pdf', $collegeId, $studentId, 'fixed-name'),
            'original_filename' => 'tc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'verification_status' => 'pending',
        ]);
    }

    public function test_index_only_shows_documents_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('DTA');
        $collegeB = $this->makeCollege('DTB');
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-DOCA']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-DOCB']);
        $this->upload($collegeA->id, $studentA->id);
        $this->upload($collegeB->id, $studentB->id);
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_documents.view']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('student-documents.index'))
            ->assertSee('STU-DOCA')
            ->assertDontSee('STU-DOCB');
    }

    public function test_cross_college_document_cannot_be_read_edited_verified_downloaded_or_deleted(): void
    {
        $collegeA = $this->makeCollege('DTXA');
        $collegeB = $this->makeCollege('DTXB');
        $studentB = $this->makeStudent($collegeB);
        $foreign = $this->upload($collegeB->id, $studentB->id);
        $adminA = $this->makeUserWithPermissions($collegeA, [
            'student_documents.view',
            'student_documents.update',
            'student_documents.delete',
        ]);

        $this->asCollege($collegeA, $adminA)->get(route('student-documents.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->get(route('student-documents.download', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->post(route('student-documents.verify', $foreign), ['action' => 'verify'])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->put(route('student-documents.update', $foreign), ['title' => 'Hacked'], ['Referer' => route('student-documents.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->delete(route('student-documents.destroy', $foreign), [], ['Referer' => route('student-documents.index')])
            ->assertNotFound();

        $this->assertSame('Transfer Certificate', $foreign->fresh()->title);
        $this->assertSame('pending', $foreign->fresh()->verification_status);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_foreign_student_and_document_type_are_rejected_on_upload(): void
    {
        $collegeA = $this->makeCollege('DTFA');
        $collegeB = $this->makeCollege('DTFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_documents.create']);
        $studentB = $this->makeStudent($collegeB);
        $foreignType = $this->makeDocumentType($collegeB, 'FOREIGN');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-documents.store'), [
                'student_id' => $studentB->id,
                'title' => 'Foreign student',
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('student_id');

        $studentA = $this->makeStudent($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-documents.store'), [
                'student_id' => $studentA->id,
                'document_type_id' => $foreignType->id,
                'title' => 'Foreign type',
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('document_type_id');

        $this->assertSame(0, StudentDocument::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_download_requires_the_view_permission(): void
    {
        $college = $this->makeCollege('DTPERM');
        $student = $this->makeStudent($college);
        $document = $this->upload($college->id, $student->id);
        Storage::disk('private')->put($document->file_path, 'dummy-pdf-bytes');

        $nobody = $this->makeUserWithPermissions($college, []);
        $creatorOnly = $this->makeUserWithPermissions($college, ['student_documents.create']);

        $this->asCollege($college, $nobody)->get(route('student-documents.download', $document))->assertForbidden();
        $this->asCollege($college, $creatorOnly)->get(route('student-documents.download', $document))->assertForbidden();

        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);
        $this->asCollege($college, $viewer)->get(route('student-documents.download', $document))->assertOk();
    }

    public function test_download_streams_the_file_and_is_audited(): void
    {
        $college = $this->makeCollege('DTDL');
        $student = $this->makeStudent($college);
        $document = $this->upload($college->id, $student->id);
        Storage::disk('private')->put($document->file_path, 'pdf-bytes');
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);

        $response = $this->asCollege($college, $viewer)->get(route('student-documents.download', $document));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=tc.pdf');
        $this->assertSame('pdf-bytes', $response->streamedContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_document.downloaded', 'college_id' => $college->id]);
    }

    public function test_a_hostile_original_filename_cannot_reach_the_download_header(): void
    {
        $college = $this->makeCollege('DTNAME');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);

        // The stored name is display text only; it is sanitised before it can be
        // used in a Content-Disposition header (a "/" or "%" there would make the
        // response builder throw, and CRLF would inject a header).
        foreach ([
            '../../../etc/passwd' => 'etcpasswd',
            '..\\..\\evil.pdf' => 'evil.pdf',
            '100%.pdf' => '100.pdf',
        ] as $hostile => $expected) {
            $document = $this->upload($college->id, $student->id);
            StudentDocument::withoutGlobalScopes()->whereKey($document->id)->update(['original_filename' => $hostile]);
            Storage::disk('private')->put($document->file_path, 'bytes');

            $this->asCollege($college, $viewer)
                ->get(route('student-documents.download', $document))
                ->assertOk()
                ->assertHeader('content-disposition', 'attachment; filename='.$expected);
        }

        $document = $this->upload($college->id, $student->id);
        StudentDocument::withoutGlobalScopes()->whereKey($document->id)
            ->update(['original_filename' => "evil\r\nX-Injected: 1.pdf"]);
        Storage::disk('private')->put($document->file_path, 'bytes');

        $response = $this->asCollege($college, $viewer)->get(route('student-documents.download', $document));
        $response->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    public function test_tampered_stored_path_is_refused(): void
    {
        $college = $this->makeCollege('DTPATH');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);

        foreach (['../../../etc/passwd', '/etc/passwd', 'students/../../secret.pdf', "students/\0evil.pdf"] as $unsafe) {
            $document = $this->upload($college->id, $student->id);
            StudentDocument::withoutGlobalScopes()->whereKey($document->id)->update(['file_path' => $unsafe]);

            $this->asCollege($college, $viewer)
                ->get(route('student-documents.download', $document))
                ->assertForbidden();
        }
    }

    public function test_missing_file_returns_not_found(): void
    {
        $college = $this->makeCollege('DTMISS');
        $student = $this->makeStudent($college);
        $document = $this->upload($college->id, $student->id);
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view']);

        $this->asCollege($college, $viewer)->get(route('student-documents.download', $document))->assertNotFound();
    }
}
