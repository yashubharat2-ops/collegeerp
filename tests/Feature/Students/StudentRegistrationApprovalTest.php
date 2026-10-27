<?php

namespace Tests\Feature\Students;

use App\Domain\Finance\Support\FeeFormOptions;
use App\Models\ApprovalRequest;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentRegistrationApprovalTest extends TestCase
{
    use StudentTestHelpers;

    private function payload($college, $year, $program, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'gender' => 'female',
            'date_of_birth' => '2008-06-15',
            'admission_date' => '2026-06-01',
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'enrollment_date' => '2026-06-02',
            'phone' => '9111111111',
            // Client attempts to force approval; the server must ignore these.
            'status' => 'active',
            'registration_status' => 'approved',
            'registration_submitted_at' => '2000-01-01 00:00:00',
        ], $overrides);
    }

    private function submitRegistration($college, $submitter, array $payload)
    {
        return $this->asCollege($college, $submitter)
            ->post(route('students.registration.store'), $payload);
    }

    private function pendingRequest(Student $student): ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $student->college_id)
            ->where('module', 'student')
            ->where('action', 'registration_approval')
            ->where('target_id', $student->id)
            ->firstOrFail();
    }

    public function test_registration_entry_point_opens_the_wizard_and_client_status_is_ignored(): void
    {
        $college = $this->makeCollege('REGF');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $user = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        // Since the five-step wizard, the registration entry point starts the
        // wizard instead of rendering the single long Student form. The form
        // itself is unchanged for the non-registration Create/Edit pages.
        $this->asCollege($college, $user)
            ->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Basic Information')
            ->assertDontSee('name="status"', false);

        $this->submitRegistration($college, $user, $this->payload($college, $year, $program))
            ->assertRedirect(route('students.index'))
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame('inactive', $student->status);
        $this->assertSame(Student::REGISTRATION_PENDING, $student->registration_status);
        $this->assertNotSame('2000-01-01 00:00:00', $student->registration_submitted_at?->format('Y-m-d H:i:s'));
    }

    public function test_submission_creates_one_pending_student_approval_request_and_audit(): void
    {
        $college = $this->makeCollege('REGS');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $user = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->submitRegistration($college, $user, $this->payload($college, $year, $program))
            ->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->assertSame('pending', $request->status);
        $this->assertSame('student.registration_approval', $request->target_type);
        $this->assertSame($year->id, $request->payload['academic_year_id']);
        $this->assertSame($program->id, $request->payload['program_id']);
        $this->assertDatabaseHas('audit_logs', [
            'college_id' => $college->id,
            'action' => 'student.registration_submitted',
            'subject_type' => (new Student)->getMorphClass(),
            'subject_id' => $student->id,
        ]);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    public function test_registration_documents_are_stored_through_the_existing_student_document_architecture(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('REGD');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $type = $this->makeDocumentType($college, 'MARKSHEET');
        $user = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $payload = $this->payload($college, $year, $program) + [
            'registration_document_type_id' => $type->id,
            'registration_documents' => [UploadedFile::fake()->create('marks.pdf', 10, 'application/pdf')],
        ];

        $this->submitRegistration($college, $user, $payload)->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $document = StudentDocument::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();
        $this->assertSame($type->id, $document->document_type_id);
        $this->assertSame('pending', $document->verification_status);
    }

    public function test_duplicate_submission_returns_the_existing_pending_registration(): void
    {
        $college = $this->makeCollege('REGU');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $user = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $payload = $this->payload($college, $year, $program);

        $this->submitRegistration($college, $user, $payload)->assertRedirect(route('students.index'));
        $this->submitRegistration($college, $user, $payload)
            ->assertRedirect(route('students.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('module', 'student')
            ->where('action', 'registration_approval')
            ->count());
    }

    public function test_resubmission_after_cancelled_request_reuses_the_pending_student(): void
    {
        $college = $this->makeCollege('REGC');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $payload = $this->payload($college, $year, $program);

        $this->submitRegistration($college, $submitter, $payload)->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($college, $submitter)
            ->post(route('approvals.cancel', $request), [])
            ->assertRedirect();

        $this->submitRegistration($college, $submitter, $payload)
            ->assertRedirect(route('students.index'));

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('module', 'student')
            ->where('action', 'registration_approval')
            ->where('status', 'pending')
            ->count());
    }

    public function test_pending_and_rejected_registrations_are_hidden_but_legacy_students_remain_visible(): void
    {
        $college = $this->makeCollege('REGV');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $legacy = $this->makeStudent($college, ['student_number' => 'STU-LEGACY', 'first_name' => 'Legacy']);
        $pending = $this->makeStudent($college, [
            'student_number' => 'STU-PENDING', 'first_name' => 'Pending',
            'status' => 'inactive', 'registration_status' => Student::REGISTRATION_PENDING,
        ]);
        $rejected = $this->makeStudent($college, [
            'student_number' => 'STU-REJECTED', 'first_name' => 'Rejected',
            'status' => 'inactive', 'registration_status' => Student::REGISTRATION_REJECTED,
        ]);

        $response = $this->asCollege($college, $viewer)->get(route('students.index'))->assertOk();
        $response->assertSee($legacy->student_number);
        $response->assertDontSee($pending->student_number);
        $response->assertDontSee($rejected->student_number);

        // Explicit administrative review can still filter pending registrations.
        $this->asCollege($college, $viewer)
            ->get(route('students.index', ['registration_status' => Student::REGISTRATION_PENDING]))
            ->assertOk()
            ->assertSee($pending->student_number)
            ->assertDontSee($legacy->student_number);
    }

    public function test_pending_students_are_excluded_from_operational_pickers(): void
    {
        $college = $this->makeCollege('REGP');
        $pending = $this->makeStudent($college, [
            'student_number' => 'STU-PENDING-PICK',
            'status' => 'inactive',
            'registration_status' => Student::REGISTRATION_PENDING,
        ]);
        $approved = $this->makeStudent($college, [
            'student_number' => 'STU-APPROVED-PICK',
            'registration_status' => Student::REGISTRATION_APPROVED,
        ]);

        app(TenantContext::class)->set($college);
        $ids = FeeFormOptions::students()->pluck('id')->all();

        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_unauthorized_approval_is_forbidden_and_self_approval_is_blocked(): void
    {
        $college = $this->makeCollege('REGA');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'approvals.approve']);
        $outsider = $this->makeUserWithPermissions($college, ['approvals.view']);

        $this->submitRegistration($college, $submitter, $this->payload($college, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($college, $outsider)
            ->post(route('approvals.approve', $request), [])
            ->assertForbidden();

        $this->asCollege($college, $submitter)
            ->post(route('approvals.approve', $request), [])
            ->assertSessionHasErrors('decided_by');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(Student::REGISTRATION_PENDING, $student->fresh()->registration_status);
    }

    public function test_approval_makes_student_visible_creates_one_enrollment_and_is_idempotent(): void
    {
        $college = $this->makeCollege('REGO');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->submitRegistration($college, $submitter, $this->payload($college, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Verified'])
            ->assertRedirect();

        $student->refresh();
        $this->assertSame(Student::REGISTRATION_APPROVED, $student->registration_status);
        $this->assertSame('active', $student->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.registration_approved',
            'subject_type' => (new Student)->getMorphClass(),
            'subject_id' => $student->id,
        ]);

        $this->asCollege($college, $submitter)->get(route('students.index'))->assertOk()->assertSee($student->student_number);

        // Repeated decision is rejected by ApprovalService; no duplicate enrollment.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), [])
            ->assertSessionHasErrors('status');
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    public function test_rejection_keeps_student_inactive_and_creates_no_enrollment(): void
    {
        $college = $this->makeCollege('REGR');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->submitRegistration($college, $submitter, $this->payload($college, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), ['decision_reason' => 'Documents incomplete'])
            ->assertRedirect();

        $student->refresh();
        $this->assertSame(Student::REGISTRATION_REJECTED, $student->registration_status);
        $this->assertSame('inactive', $student->status);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.registration_rejected',
            'subject_type' => (new Student)->getMorphClass(),
            'subject_id' => $student->id,
        ]);

        $this->asCollege($college, $submitter)->get(route('students.index'))->assertOk()->assertDontSee($student->student_number);
    }

    public function test_failed_approval_does_not_leave_partial_state(): void
    {
        $college = $this->makeCollege('REGX');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->submitRegistration($college, $submitter, $this->payload($college, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        // Remove the referenced academic year so approval side effects fail.
        $year->forceDelete();

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), [])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(Student::REGISTRATION_PENDING, $student->fresh()->registration_status);
        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    public function test_student_profile_and_history_work_after_approval(): void
    {
        $college = $this->makeCollege('REGH');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $submitter = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->submitRegistration($college, $submitter, $this->payload($college, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Verified'])
            ->assertRedirect();

        $this->asCollege($college, $submitter)
            ->get(route('students.show', ['student' => $student, 'tab' => 'history']))
            ->assertOk()
            ->assertSee($student->student_number)
            ->assertSee('Registration submitted')
            ->assertSee('Registration approved');
    }

    public function test_approval_request_is_tenant_scoped(): void
    {
        $collegeA = $this->makeCollege('REGT1');
        $collegeB = $this->makeCollege('REGT2');
        $year = $this->makeYear($collegeA);
        $program = $this->makeProgram($collegeA);
        $submitter = $this->makeUserWithPermissions($collegeA, ['students.view', 'students.create']);
        $foreignApprover = $this->makeUserWithPermissions($collegeB, ['approvals.view', 'approvals.approve']);

        $this->submitRegistration($collegeA, $submitter, $this->payload($collegeA, $year, $program))->assertRedirect();
        $student = Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->firstOrFail();
        $request = $this->pendingRequest($student);

        $this->asCollege($collegeB, $foreignApprover)
            ->post(route('approvals.approve', $request), [])
            ->assertNotFound();

        $this->assertSame('pending', $request->fresh()->status);
    }
}
