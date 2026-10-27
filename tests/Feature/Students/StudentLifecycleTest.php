<?php

namespace Tests\Feature\Students;

use App\Models\Admission;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentRegistrationDraft;
use App\Services\Approval\ApprovalRegistry;
use Tests\TestCase;

/**
 * The student lifecycle end to end, as the office experiences it:
 *
 *   Registration draft → Submission → Registration approval (= confirmed
 *   admission) → Enrollment → Enrollment approval (optional per college) →
 *   Student List.
 *
 * The tests pin the properties the lifecycle must never lose: a draft creates no
 * student, a submission creates exactly one pending student, the Student ID is
 * issued once and reused, the Admission Number belongs to the admission
 * workflow, an enrollment references an existing eligible student, a saved
 * enrollment is not automatically active when the college requires approval,
 * only an authorized approver can activate it, and history is preserved.
 */
class StudentLifecycleTest extends TestCase
{
    use StudentTestHelpers;

    /** Turn the (default-off) enrollment approval gate on for one college. */
    private function requireEnrollmentApproval($college): ApprovalSetting
    {
        return ApprovalSetting::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'module' => 'student',
            'action' => 'enrollment_approval',
            'is_required' => true,
            'label' => 'Student Enrollment',
        ]);
    }

    private function registrationPayload($year, $program, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Aarav',
            'last_name' => 'Shah',
            'gender' => 'male',
            'date_of_birth' => '2008-04-12',
            'admission_date' => '2026-06-01',
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'enrollment_date' => '2026-06-02',
            'phone' => '9222222222',
        ], $overrides);
    }

    private function pendingRequestFor(Student $student, string $action = 'registration_approval'): ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $student->college_id)
            ->where('module', 'student')
            ->where('action', $action)
            ->where('status', 'pending')
            ->latest('id')
            ->firstOrFail();
    }

    // ---------------------------------------------------------------- A. Draft

    public function test_a_registration_draft_never_creates_a_student_enrollment_or_admission(): void
    {
        $college = $this->makeCollege('LCY1');
        $staff = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $staff)
            ->post(route('students.registration.step.draft', ['step' => 1]), [
                'first_name' => 'Aarav',
                'last_name' => 'Shah',
            ])
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $draft = StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('user_id', $staff->id)->firstOrFail();
        $stepAfterSave = $draft->current_step;

        // Re-opening the wizard repeatedly is a READ: it must not advance the
        // resume point, and must never mint a student / number.
        $this->asCollege($college, $staff)->get(route('students.registration.step', ['step' => 1]))->assertOk();
        $this->asCollege($college, $staff)->get(route('students.registration.step', ['step' => 1]))->assertOk();

        $this->assertSame($stepAfterSave, $draft->fresh()->current_step);

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    // ----------------------------------------------------------- B. Submission

    public function test_submission_creates_one_pending_student_with_an_id_and_no_enrollment(): void
    {
        $college = $this->makeCollege('LCY2');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $staff = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $staff)
            ->post(route('students.registration.store'), $this->registrationPayload($year, $program))
            ->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();

        // Identity is issued at submission — not when the wizard was opened — so
        // the office has a reference to talk about while it decides.
        $this->assertNotNull($student->student_number);
        $this->assertSame(Student::REGISTRATION_PENDING, $student->registration_status);
        $this->assertSame('inactive', $student->status);

        // A submitted registration is NOT an enrollment and NOT an admission.
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // One approval request; a second submission reuses the pending student
        // instead of creating a duplicate person.
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('module', 'student')
            ->where('action', 'registration_approval')->count());

        $this->asCollege($college, $staff)
            ->post(route('students.registration.store'), $this->registrationPayload($year, $program))
            ->assertRedirect();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('module', 'student')
            ->where('action', 'registration_approval')->count());
    }

    // ------------------------------------------------ C/D. Approval == admission

    public function test_registration_approval_issues_the_student_id_once_and_confirms_admission(): void
    {
        $college = $this->makeCollege('LCY3');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $staff = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->asCollege($college, $staff)
            ->post(route('students.registration.store'), $this->registrationPayload($year, $program))
            ->assertRedirect();

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $numberAtSubmission = $student->student_number;
        $request = $this->pendingRequestFor($student);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Verified'])
            ->assertRedirect();

        $student->refresh();
        $this->assertSame(Student::REGISTRATION_APPROVED, $student->registration_status);
        $this->assertSame('active', $student->status);

        // The Student ID is stable across the decision: approval finalizes the
        // person, it does not re-issue the identity.
        $this->assertSame($numberAtSubmission, $student->student_number);

        // Approval creates the ONE initial enrollment, numbered server-side for
        // the registration's own academic year.
        $enrollments = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->get();
        $this->assertCount(1, $enrollments);
        $this->assertSame('active', $enrollments->first()->status);
        $this->assertStringStartsWith('ENR-', $enrollments->first()->enrollment_number);
        $this->assertSame($year->id, $enrollments->first()->academic_year_id);

        // Registration approval IS the confirmed admission for a direct
        // registration: no second admission workflow is invented, and no
        // Admission Number is minted by it (that number belongs to the separate
        // applicant → admission workflow).
        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // Repeating the decision changes nothing.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), [])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
        $this->assertSame($numberAtSubmission, $student->fresh()->student_number);
    }

    // ------------------------------------------------- E. Enrollment creation

    public function test_an_enrollment_requires_an_existing_eligible_student(): void
    {
        $college = $this->makeCollege('LCY4');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);

        $pending = $this->makeStudent($college, [
            'student_number' => 'STU-PEND-ENR',
            'status' => 'inactive',
            'registration_status' => Student::REGISTRATION_PENDING,
        ]);
        $rejected = $this->makeStudent($college, [
            'student_number' => 'STU-REJ-ENR',
            'status' => 'inactive',
            'registration_status' => Student::REGISTRATION_REJECTED,
        ]);

        foreach ([$pending, $rejected] as $student) {
            $this->asCollege($college, $admin)
                ->post(route('student-enrollments.store'), [
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'program_id' => $program->id,
                    'status' => 'active',
                ])
                ->assertSessionHasErrors('student_id');
        }

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The picker offers only eligible students, and identifies each by
        // Student ID + name (+ admission when there is one).
        $approved = $this->makeStudent($college, [
            'student_number' => 'STU-OK-ENR',
            'first_name' => 'Eligible',
            'registration_status' => Student::REGISTRATION_APPROVED,
        ]);

        $this->asCollege($college, $admin)
            ->get(route('student-enrollments.create'))
            ->assertOk()
            ->assertSee('STU-OK-ENR')
            ->assertSee('Eligible')
            ->assertDontSee('STU-PEND-ENR')
            ->assertDontSee('STU-REJ-ENR');

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $approved->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $approved->id)->firstOrFail();
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
        $this->assertSame('STU-OK-ENR', $approved->fresh()->student_number);
    }

    public function test_workflow_statuses_cannot_be_chosen_when_creating_or_editing_an_enrollment(): void
    {
        $college = $this->makeCollege('LCY5');
        $year = $this->makeYear($college);
        $admin = $this->makeUserWithPermissions($college, [
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update',
        ]);
        $student = $this->makeStudent($college);

        foreach (['pending_approval', 'rejected'] as $workflowStatus) {
            $this->asCollege($college, $admin)
                ->post(route('student-enrollments.store'), [
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'status' => $workflowStatus,
                ])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['status' => 'active']);

        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'pending_approval'])
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_a_second_enrollment_keeps_the_student_id_and_preserves_the_first(): void
    {
        $college = $this->makeCollege('LCY6');
        $year = $this->makeYear($college, '2026', '2026-27');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);

        $student = $this->makeStudent($college, [
            'student_number' => 'STU-REUSE',
            'registration_status' => Student::REGISTRATION_APPROVED,
        ]);

        $first = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $nextYear->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $second = StudentEnrollment::withoutGlobalScopes()
            ->where('student_id', $student->id)->where('academic_year_id', $nextYear->id)->firstOrFail();

        $this->assertNotSame($first->enrollment_number, $second->enrollment_number);
        $this->assertSame('STU-REUSE', $student->fresh()->student_number);

        // History is preserved, not overwritten: the old row still exists.
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());

        // The same student + year + programme is still a duplicate tuple.
        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $nextYear->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');
    }

    // --------------------------------------------------- F. Enrollment approval

    public function test_a_gated_enrollment_is_saved_as_pending_and_only_an_approver_can_activate_it(): void
    {
        $college = $this->makeCollege('LCY7');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $clerk = $this->makeUserWithPermissions($college, [
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update',
        ]);
        $outsider = $this->makeUserWithPermissions($college, ['approvals.view']);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve', 'student_enrollments.approve']);

        $this->requireEnrollmentApproval($college);

        $student = $this->makeStudent($college, [
            'student_number' => 'STU-GATED',
            'registration_status' => Student::REGISTRATION_APPROVED,
        ]);

        $this->asCollege($college, $clerk)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertRedirect(route('student-enrollments.index'));

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();

        // Saved is not approved: the row keeps its numbers but is not operational.
        $this->assertSame(StudentEnrollment::STATUS_PENDING_APPROVAL, $enrollment->status);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);

        $request = $this->pendingRequestFor($student, 'enrollment_approval');
        $this->assertSame('student.enrollment_approval', $request->target_type);
        $this->assertSame($enrollment->id, $request->target_id);
        $this->assertSame('student_enrollments.approve', ApprovalRegistry::permissionFor('student', 'enrollment_approval'));

        // It cannot be flipped to active through the ordinary edit form.
        $this->asCollege($college, $clerk)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'])
            ->assertSessionHasErrors('status');
        $this->assertSame(StudentEnrollment::STATUS_PENDING_APPROVAL, $enrollment->fresh()->status);

        // Nor by someone without the enrollment-approval permission.
        $this->asCollege($college, $outsider)
            ->post(route('approvals.approve', $request), [])
            ->assertForbidden();

        // The authorized approver decides, and it becomes the active enrollment.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Seat confirmed'])
            ->assertRedirect();

        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'college_id' => $college->id,
            'action' => 'student_enrollment.approved',
            'subject_type' => (new StudentEnrollment)->getMorphClass(),
            'subject_id' => $enrollment->id,
        ]);

        // A repeated decision is refused and creates nothing new.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), [])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    public function test_a_rejected_enrollment_is_not_active_and_is_never_resurrected(): void
    {
        $college = $this->makeCollege('LCY8');
        $year = $this->makeYear($college);
        $clerk = $this->makeUserWithPermissions($college, [
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update',
        ]);
        $approver = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve', 'student_enrollments.approve']);

        $this->requireEnrollmentApproval($college);

        $student = $this->makeStudent($college, ['registration_status' => Student::REGISTRATION_APPROVED]);

        $this->asCollege($college, $clerk)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();
        $request = $this->pendingRequestFor($student, 'enrollment_approval');

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), ['decision_reason' => 'Duplicate seat'])
            ->assertRedirect();

        $this->assertSame(StudentEnrollment::STATUS_REJECTED, $enrollment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_enrollment.rejected',
            'subject_type' => (new StudentEnrollment)->getMorphClass(),
            'subject_id' => $enrollment->id,
        ]);

        // Active is now refused on the record, and the enrollment number is kept.
        $this->asCollege($college, $clerk)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'])
            ->assertSessionHasErrors('status');

        $enrollment->refresh();
        $this->assertSame(StudentEnrollment::STATUS_REJECTED, $enrollment->status);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);

        // A pending/accepted row for the same tuple is not created behind it.
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    // -------------------------------------------------------- G. Student list

    public function test_an_approved_student_without_an_enrollment_remains_visible(): void
    {
        $college = $this->makeCollege('LCY9');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $approved = $this->makeStudent($college, [
            'student_number' => 'STU-NO-ENR',
            'first_name' => 'Admitted',
            'registration_status' => Student::REGISTRATION_APPROVED,
        ]);
        $pending = $this->makeStudent($college, [
            'student_number' => 'STU-WAITING',
            'status' => 'inactive',
            'registration_status' => Student::REGISTRATION_PENDING,
        ]);

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $approved->id)->count());

        $this->asCollege($college, $viewer)
            ->get(route('students.index'))
            ->assertOk()
            ->assertSee('STU-NO-ENR')
            ->assertDontSee('STU-WAITING');

        // Pending registrations stay reviewable in the administrative view.
        $this->asCollege($college, $viewer)
            ->get(route('students.index', ['registration_status' => Student::REGISTRATION_PENDING]))
            ->assertOk()
            ->assertSee('STU-WAITING');
    }

    // ------------------------------------------------------- Tenant isolation

    public function test_enrollment_approval_is_tenant_scoped(): void
    {
        $collegeA = $this->makeCollege('LCYA');
        $collegeB = $this->makeCollege('LCYB');
        $year = $this->makeYear($collegeA);
        $clerk = $this->makeUserWithPermissions($collegeA, [
            'student_enrollments.view', 'student_enrollments.create',
        ]);
        $foreignApprover = $this->makeUserWithPermissions($collegeB, [
            'approvals.view', 'approvals.approve', 'student_enrollments.approve',
        ]);

        $this->requireEnrollmentApproval($collegeA);

        $student = $this->makeStudent($collegeA, ['registration_status' => Student::REGISTRATION_APPROVED]);

        $this->asCollege($collegeA, $clerk)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();
        $request = $this->pendingRequestFor($student, 'enrollment_approval');

        $this->asCollege($collegeB, $foreignApprover)
            ->post(route('approvals.approve', $request), [])
            ->assertNotFound();

        $this->assertSame(StudentEnrollment::STATUS_PENDING_APPROVAL, $enrollment->fresh()->status);
        $this->assertSame('pending', $request->fresh()->status);
    }
}
