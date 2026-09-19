<?php

namespace Tests\Feature\Students;

use App\Models\Student;
use App\Models\StudentTransfer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tenant isolation for transfers: cross-college ids 404, every reference is
 * tenant-validated, and the stored file path can never come from the client.
 */
class StudentTransferTenancyTest extends TestCase
{
    use StudentTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_index_only_shows_transfers_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('TRTA');
        $collegeB = $this->makeCollege('TRTB');
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-TA']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-TB']);
        $this->makeTransfer($collegeA, $studentA, ['tc_number' => 'TC-2026-0001', 'tc_status' => 'issued']);
        $this->makeTransfer($collegeB, $studentB, ['tc_number' => 'TC-2026-0002', 'tc_status' => 'issued']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['student_transfers.view']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('student-transfers.index'))
            ->assertSee('STU-TA')
            ->assertDontSee('STU-TB')
            ->assertDontSee('TC-2026-0002');
    }

    public function test_cross_college_transfer_is_not_found(): void
    {
        $collegeA = $this->makeCollege('TRXA');
        $collegeB = $this->makeCollege('TRXB');
        $studentB = $this->makeStudent($collegeB);
        $foreign = $this->makeTransfer($collegeB, $studentB);

        $adminA = $this->makeUserWithPermissions($collegeA, ['student_transfers.view', 'student_transfers.update', 'student_transfers.approve']);

        foreach ([
            'GET' => route('student-transfers.edit', $foreign),
            'GET' => route('student-transfers.download', $foreign),
            'POST' => route('student-transfers.approve', $foreign),
            'POST' => route('student-transfers.reject', $foreign),
            'POST' => route('student-transfers.issue', $foreign),
            'POST' => route('student-transfers.cancel', $foreign),
        ] as $method => $uri) {
            $this->asCollege($collegeA, $adminA)->json($method, $uri)->assertNotFound();
        }

        $this->assertSame('pending', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->tc_number);
    }

    public function test_foreign_student_and_enrollment_are_rejected(): void
    {
        $collegeA = $this->makeCollege('TRFA');
        $collegeB = $this->makeCollege('TRFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_transfers.create']);
        $yearA = $this->makeYear($collegeA);
        $programA = $this->makeProgram($collegeA);
        $studentA = $this->makeStudent($collegeA);
        $enrollmentA = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA);

        $yearB = $this->makeYear($collegeB);
        $programB = $this->makeProgram($collegeB);
        $studentB = $this->makeStudent($collegeB);
        $enrollmentB = $this->makeEnrollment($collegeB, $studentB, $yearB, $programB);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-transfers.store'), [
                'student_id' => $studentB->id,
                'transfer_date' => '2026-08-01',
                'reason' => 'Relocation',
            ])
            ->assertSessionHasErrors('student_id');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-transfers.store'), [
                'student_id' => $studentA->id,
                'enrollment_id' => $enrollmentB->id,
                'transfer_date' => '2026-08-01',
                'reason' => 'Relocation',
            ])
            ->assertSessionHasErrors('enrollment_id');

        $this->assertSame(0, StudentTransfer::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame('active', $studentA->fresh()->status);
        $this->assertSame('active', $enrollmentA->fresh()->status);
    }

    public function test_college_id_and_workflow_state_from_browser_are_ignored(): void
    {
        $collegeA = $this->makeCollege('TRSAFE');
        $collegeB = $this->makeCollege('TRSAFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_transfers.create']);
        $studentA = $this->makeStudent($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-transfers.store'), [
                'college_id' => $collegeB->id,
                'student_id' => $studentA->id,
                'transfer_date' => '2026-08-01',
                'reason' => 'Relocation',
                'tc_number' => 'TC-1999-9999',
                'tc_status' => 'issued',
                'tc_issue_date' => '2026-08-02',
                'tc_file_path' => 'admissions/1/forged.pdf',
                'status' => 'approved',
                'requested_by' => $adminA->id,
            ])
            ->assertSessionHasNoErrors();

        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $collegeA->id)->first();

        $this->assertNotNull($transfer);
        $this->assertNull($transfer->tc_number);
        $this->assertSame('pending', $transfer->tc_status);
        $this->assertNull($transfer->tc_issue_date);
        $this->assertNull($transfer->tc_file_path);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame(0, StudentTransfer::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
        $this->assertSame('active', $studentA->fresh()->status);
    }

    public function test_uploaded_file_is_stored_under_the_active_tenant_path_only(): void
    {
        $collegeA = $this->makeCollege('TRFILE');
        $admin = $this->makeUserWithPermissions($collegeA, ['student_transfers.create', 'student_transfers.approve']);
        $student = $this->makeStudent($collegeA, ['student_number' => 'STU-TR-FILE']);

        $this->asCollege($collegeA, $admin)
            ->post(route('student-transfers.store'), [
                'student_id' => $student->id,
                'transfer_date' => '2026-08-01',
                'reason' => 'Relocation',
            ])
            ->assertSessionHasNoErrors();

        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $collegeA->id)->first();
        $this->asCollege($collegeA, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');

        $this->asCollege($collegeA, $admin)
            ->post(route('student-transfers.issue', $transfer), ['tc_file' => UploadedFile::fake()->create('../../evil.pdf', 10, 'application/pdf')])
            ->assertSessionHas('success');

        $transfer->refresh();

        // The uploaded name never becomes part of the stored path: the path is
        // built from the tenant, the student and a random filename.
        $this->assertStringStartsWith(
            sprintf('students/%d/%d/tc/', $collegeA->id, $student->id),
            $transfer->tc_file_path
        );
        $this->assertStringNotContainsString('..', $transfer->tc_file_path);
        $this->assertStringNotContainsString('evil', $transfer->tc_file_path);
        Storage::disk('private')->assertExists($transfer->tc_file_path);

        // The raw name is kept for display only, and is sanitised before it can
        // reach a Content-Disposition header.
        $response = $this->asCollege($collegeA, $admin)->get(route('student-transfers.download', $transfer));
        $response->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('evil.pdf', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', $disposition);

        // Issuing the TC withdrew the student; nothing was deleted.
        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }
}
