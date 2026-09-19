<?php

namespace Tests\Feature\Students;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransfer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Transfer / TC workflow.
 *
 * The rule under test in every case: a transfer changes STATUSES and records the
 * TC — it never deletes the student, their enrollments or their history.
 */
class StudentTransferTest extends TestCase
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
            'transfer_date' => '2026-08-01',
            'reason' => 'Family relocation to another city',
            'destination_institution' => 'Model Science College, Indore',
            'remarks' => 'Requested by guardian',
        ], $overrides);
    }

    public function test_index_requires_the_view_permission(): void
    {
        $college = $this->makeCollege('TRPERM');
        $nobody = $this->makeUserWithPermissions($college, []);
        $viewer = $this->makeUserWithPermissions($college, ['student_transfers.view']);

        $this->asCollege($college, $nobody)->get(route('student-transfers.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('student-transfers.index'))->assertOk()->assertSee('Student Transfer / TC');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-transfers.index'))->assertRedirect(route('login'));
    }

    public function test_workflow_permissions_are_separate(): void
    {
        $college = $this->makeCollege('TRPERMS');
        $student = $this->makeStudent($college);
        $transfer = $this->makeTransfer($college, $student);
        $creator = $this->makeUserWithPermissions($college, ['student_transfers.view', 'student_transfers.create']);

        $this->asCollege($college, $creator)->get(route('student-transfers.create'))->assertOk();
        $this->asCollege($college, $creator)->post(route('student-transfers.approve', $transfer))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('student-transfers.issue', $transfer))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('student-transfers.cancel', $transfer))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('student-transfers.reject', $transfer))->assertForbidden();
        $this->asCollege($college, $creator)->get(route('student-transfers.edit', $transfer))->assertForbidden();

        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_transfer_request_is_recorded_as_pending(): void
    {
        $college = $this->makeCollege('TRREQ');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.view', 'student_transfers.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['student_number' => 'STU-TR-1']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-transfers.store'), $this->payload($student->id, ['enrollment_id' => $enrollment->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-transfers.index'));

        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertNotNull($transfer);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame('pending', $transfer->tc_status);
        $this->assertNull($transfer->tc_number);
        $this->assertSame($student->id, $transfer->student_id);
        $this->assertSame($enrollment->id, $transfer->enrollment_id);
        $this->assertSame($admin->id, $transfer->requested_by);
        $this->assertSame('active', $student->fresh()->status, 'Requesting a transfer must not change the student yet.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transfer.requested']);
    }

    public function test_validation_requires_student_date_and_reason(): void
    {
        $college = $this->makeCollege('TRVAL');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.create']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)
            ->post(route('student-transfers.store'), ['student_id' => $student->id])
            ->assertSessionHasErrors(['transfer_date', 'reason']);

        $this->asCollege($college, $admin)
            ->post(route('student-transfers.store'), $this->payload($student->id, ['transfer_date' => 'not-a-date']))
            ->assertSessionHasErrors('transfer_date');

        $this->assertSame(0, StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_approval_then_issuance_updates_statuses_and_mints_the_tc_number(): void
    {
        $college = $this->makeCollege('TRISSUE');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.view', 'student_transfers.create', 'student_transfers.update', 'student_transfers.approve']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['student_number' => 'STU-TR-2']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['enrollment_number' => 'ENR-TR-2']);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id, ['enrollment_id' => $enrollment->id]))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();

        // A TC cannot be issued before approval.
        $this->asCollege($college, $admin)
            ->post(route('student-transfers.issue', $transfer), ['tc_issue_date' => '2026-08-15'])
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $transfer->fresh()->tc_status);

        $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');
        $transfer->refresh();
        $this->assertSame('approved', $transfer->status);
        $this->assertSame($admin->id, $transfer->approved_by);
        $this->assertNotNull($transfer->approved_at);
        $this->assertSame('active', $student->fresh()->status, 'Approval alone does not withdraw the student.');

        $this->asCollege($college, $admin)
            ->post(route('student-transfers.issue', $transfer), [
                'tc_issue_date' => '2026-08-15',
                'tc_file' => UploadedFile::fake()->create('signed-tc.pdf', 60, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame('issued', $transfer->tc_status);
        $this->assertSame('TC-2026-0001', $transfer->tc_number, 'The TC number is minted server-side from the issue year.');
        $this->assertSame('2026-08-15', $transfer->tc_issue_date->format('Y-m-d'));
        $this->assertNotNull($transfer->tc_file_path);
        $this->assertStringStartsWith(sprintf('students/%d/%d/tc/', $college->id, $student->id), $transfer->tc_file_path);
        $this->assertStringNotContainsString('signed-tc', $transfer->tc_file_path);
        Storage::disk('private')->assertExists($transfer->tc_file_path);

        // Statuses change; nothing is deleted.
        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertSame('withdrawn', $enrollment->fresh()->status);
        $this->assertNull($student->fresh()->deleted_at);
        $this->assertNull($enrollment->fresh()->deleted_at);
        $this->assertSame('ENR-TR-2', $enrollment->fresh()->enrollment_number);
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transfer.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transfer.issued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.updated']);
    }

    public function test_tc_numbers_are_sequential_per_college(): void
    {
        $college = $this->makeCollege('TRSEQ');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.create', 'student_transfers.approve']);

        $numbers = [];
        foreach (['STU-TR-S1', 'STU-TR-S2'] as $index => $number) {
            $student = $this->makeStudent($college, ['student_number' => $number, 'phone' => '900000000'.$index]);
            $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id))->assertSessionHasNoErrors();
            $transfer = StudentTransfer::withoutGlobalScopes()->where('student_id', $student->id)->first();
            $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');
            $this->asCollege($college, $admin)->post(route('student-transfers.issue', $transfer))->assertSessionHas('success');
            $numbers[] = $transfer->fresh()->tc_number;
        }

        $this->assertSame('TC-2026-0001', $numbers[0]);
        $this->assertSame('TC-2026-0002', $numbers[1]);
    }

    public function test_an_issued_tc_cannot_be_issued_cancelled_or_deleted_again(): void
    {
        $college = $this->makeCollege('TRLOCK');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.create', 'student_transfers.update', 'student_transfers.approve']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');
        $this->asCollege($college, $admin)->post(route('student-transfers.issue', $transfer))->assertSessionHas('success');
        $tcNumber = $transfer->fresh()->tc_number;

        $this->asCollege($college, $admin)->post(route('student-transfers.issue', $transfer))->assertSessionHasErrors('tc_status');
        $this->asCollege($college, $admin)->post(route('student-transfers.cancel', $transfer))->assertSessionHasErrors('status');
        $this->asCollege($college, $admin)->delete(route('student-transfers.destroy', $transfer), [], ['Referer' => route('student-transfers.index')])->assertSessionHasErrors('status');

        $transfer->refresh();
        $this->assertSame('issued', $transfer->tc_status);
        $this->assertSame($tcNumber, $transfer->tc_number);
        $this->assertNull($transfer->deleted_at);
    }

    public function test_pending_request_can_be_edited_rejected_or_cancelled(): void
    {
        $college = $this->makeCollege('TREDIT');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.view', 'student_transfers.create', 'student_transfers.update', 'student_transfers.approve']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)
            ->put(route('student-transfers.update', $transfer), $this->payload($student->id, ['reason' => 'Corrected reason', 'destination_institution' => 'Another college']), ['Referer' => route('student-transfers.edit', $transfer)])
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame('Corrected reason', $transfer->reason);
        $this->assertSame('Another college', $transfer->destination_institution);
        $this->assertSame('pending', $transfer->status);

        $this->asCollege($college, $admin)->post(route('student-transfers.cancel', $transfer))->assertSessionHas('success');
        $transfer->refresh();
        $this->assertSame('cancelled', $transfer->status);
        $this->assertSame('cancelled', $transfer->tc_status);
        $this->assertNotNull($transfer->cancelled_at);

        // A closed request can no longer be edited or approved.
        $this->asCollege($college, $admin)
            ->put(route('student-transfers.update', $transfer), $this->payload($student->id, ['reason' => 'Too late']), ['Referer' => route('student-transfers.edit', $transfer)])
            ->assertSessionHasErrors('status');
        $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHasErrors('status');
        $this->assertSame('Corrected reason', $transfer->fresh()->reason);
        $this->assertSame('active', $student->fresh()->status, 'A cancelled transfer must not affect the student.');
    }

    public function test_rejecting_a_request_records_the_decision(): void
    {
        $college = $this->makeCollege('TRREJ');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.create', 'student_transfers.approve']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)
            ->post(route('student-transfers.reject', $transfer), ['remarks' => 'Dues pending'])
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame('rejected', $transfer->status);
        $this->assertSame('Dues pending', $transfer->remarks);
        $this->assertSame('pending', $transfer->tc_status);
        $this->assertSame('active', $student->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transfer.rejected']);
    }

    public function test_tc_file_can_be_downloaded_by_an_authorized_user(): void
    {
        $college = $this->makeCollege('TRDL');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.view', 'student_transfers.create', 'student_transfers.approve']);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();

        // No file attached yet.
        $this->asCollege($college, $admin)->get(route('student-transfers.download', $transfer))->assertNotFound();

        $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');
        $this->asCollege($college, $admin)
            ->post(route('student-transfers.issue', $transfer), ['tc_file' => UploadedFile::fake()->create('tc.pdf', 20, 'application/pdf')])
            ->assertSessionHas('success');

        $response = $this->asCollege($college, $admin)->get(route('student-transfers.download', $transfer));
        $response->assertOk();
        $this->assertSame('attachment; filename=tc.pdf', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transfer.downloaded']);
    }

    public function test_history_is_preserved_after_a_transfer(): void
    {
        $college = $this->makeCollege('TRHIST');
        $admin = $this->makeUserWithPermissions($college, ['student_transfers.create', 'student_transfers.approve', 'student_history.view', 'students.view']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['student_number' => 'STU-TR-HIST']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);
        $this->makeAcademicRecord($college, $student, $year);

        $this->asCollege($college, $admin)->post(route('student-transfers.store'), $this->payload($student->id, ['enrollment_id' => $enrollment->id]))->assertSessionHasNoErrors();
        $transfer = StudentTransfer::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->asCollege($college, $admin)->post(route('student-transfers.approve', $transfer))->assertSessionHas('success');
        $this->asCollege($college, $admin)->post(route('student-transfers.issue', $transfer))->assertSessionHas('success');

        // The student, enrollment and academic record all still exist.
        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('student_enrollments', ['id' => $enrollment->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('student_academic_records', ['student_id' => $student->id, 'deleted_at' => null]);

        $history = $this->asCollege($college, $admin)
            ->get(route('student-history.show', $student))
            ->assertOk();

        $history->assertSee('Transfer certificate issued');
        $history->assertSee('Enrollment created');
        $history->assertSee('Academic record');
    }
}
