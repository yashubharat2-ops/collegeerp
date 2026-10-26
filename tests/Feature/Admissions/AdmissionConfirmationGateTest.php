<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admission\Services\AdmissionService;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\Program;
use App\Models\Student;
use App\Services\Approval\ApprovalRegistry;
use App\Services\Approval\ApprovalService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase A — the two admission-confirmation defects found in the unification
 * audit (docs/admission_estudents_unification_audit.md §3.5).
 *
 * Defect 1: `AdmissionService::createFromApplication()` used a DENYLIST
 * (draft/rejected/cancelled), so an application that was merely `submitted` or
 * `under_review` — still awaiting the administrator's decision — could be
 * confirmed as a final admission. Confirmation must FOLLOW approval, which is
 * the whole premise of the unified registration workflow. The rule is now an
 * allowlist, `AdmissionService::ADMITTABLE_STATUSES`.
 *
 * Defect 2: `admission.admission_create` was declared in `ApprovalRegistry`, so
 * Approval Settings rendered a live "Create Admission requires approval" toggle,
 * but nothing ever called `submitRequest()` for it and `applyApproval()` handled
 * it with an empty branch. An administrator could enable it and nothing changed.
 * The entry is removed, so the screen no longer advertises a no-op.
 *
 * These tests also guard against OVER-correction: the previously blocked
 * statuses must stay blocked, the two live admission approval actions must
 * survive, and every other module's approval actions must be untouched.
 */
class AdmissionConfirmationGateTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeYear($college, string $code = '2026'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => $code,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
    }

    private function makeProgram($college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeApplicant($college): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Gate',
            'last_name' => 'Applicant',
            'status' => 'active',
        ]);
    }

    private function makeApplication($college, $applicant, $year = null, $program = null, string $status = 'approved'): AdmissionApplication
    {
        return AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year?->id,
            'program_id' => $program?->id,
            'application_number' => 'APP-'.uniqid(),
            'status' => $status,
        ]);
    }

    private function admissionCount($college): int
    {
        return Admission::withoutGlobalScopes()->where('college_id', $college->id)->count();
    }

    /**
     * Re-read an application's status without relying on CollegeScope.
     *
     * `ResolveTenant` sets TenantContext and nothing clears it, so a
     * `$model->fresh()` after a request does resolve inside the acting college
     * (the pattern AdmissionApplicationAuthorizationTest uses). Reading
     * explicitly with withoutGlobalScopes() removes any dependence on that
     * container-lifetime detail, so these assertions test the gate and nothing
     * else.
     */
    private function applicationStatus(AdmissionApplication $application): ?string
    {
        return AdmissionApplication::withoutGlobalScopes()
            ->whereKey($application->id)
            ->value('status');
    }

    // =====================================================================
    // Defect 1 — confirmation follows approval
    // =====================================================================

    public function test_a_submitted_application_cannot_be_confirmed_as_an_admission(): void
    {
        $college = $this->makeCollege('GATEA');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $application = $this->makeApplication($college, $this->makeApplicant($college), null, null, 'submitted');

        $this->asCollege($college, $admin)
            ->post(route('admissions.store'), ['application_id' => $application->id], ['Referer' => route('admissions.index')])
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, $this->admissionCount($college), 'Nothing may be written when confirmation precedes approval.');
        $this->assertSame('submitted', $this->applicationStatus($application), 'The application status must not be advanced by a refused confirmation.');
    }

    public function test_an_under_review_application_cannot_be_confirmed_as_an_admission(): void
    {
        $college = $this->makeCollege('GATEB');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $application = $this->makeApplication($college, $this->makeApplicant($college), null, null, 'under_review');

        $this->asCollege($college, $admin)
            ->post(route('admissions.store'), ['application_id' => $application->id], ['Referer' => route('admissions.index')])
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, $this->admissionCount($college));
        $this->assertSame('under_review', $this->applicationStatus($application));
    }

    public function test_an_approved_application_can_still_be_confirmed(): void
    {
        $college = $this->makeCollege('GATEC');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $application = $this->makeApplication($college, $this->makeApplicant($college), $year, $program, 'approved');

        $this->asCollege($college, $admin)
            ->post(route('admissions.store'), ['application_id' => $application->id, 'status' => 'active'], ['Referer' => route('admissions.index')])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->admissionCount($college), 'The allowlist must not over-block the legitimate path.');
        $this->assertSame('admitted', $this->applicationStatus($application));
    }

    public function test_an_already_admitted_application_can_still_be_confirmed(): void
    {
        // `admitted` stays admittable so the vocabulary is idempotent: an
        // application advanced to `admitted` by the bulk review action, but
        // without an admissions row yet, can still be confirmed.
        $college = $this->makeCollege('GATED');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $application = $this->makeApplication($college, $this->makeApplicant($college), null, null, 'admitted');

        $this->asCollege($college, $admin)
            ->post(route('admissions.store'), ['application_id' => $application->id], ['Referer' => route('admissions.index')])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->admissionCount($college));
    }

    public function test_the_previously_blocked_statuses_remain_blocked(): void
    {
        // Guards the behaviour AdmissionManagementTest::
        // test_cannot_admit_draft_rejected_cancelled_application already
        // asserts, so the switch from denylist to allowlist cannot silently
        // re-open one of them.
        $college = $this->makeCollege('GATEE');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $applicant = $this->makeApplicant($college);

        foreach (['draft', 'rejected', 'cancelled'] as $blocked) {
            $application = $this->makeApplication($college, $applicant, null, null, $blocked);

            $this->asCollege($college, $admin)
                ->post(route('admissions.store'), ['application_id' => $application->id], ['Referer' => route('admissions.index')])
                ->assertSessionHasErrors('application_id');
        }

        $this->assertSame(0, $this->admissionCount($college));
    }

    public function test_the_admittable_vocabulary_is_an_allowlist_of_approved_and_admitted(): void
    {
        $this->assertSame(['approved', 'admitted'], AdmissionService::ADMITTABLE_STATUSES);

        foreach (['draft', 'submitted', 'under_review', 'rejected', 'cancelled'] as $notAdmittable) {
            $this->assertNotContains(
                $notAdmittable,
                AdmissionService::ADMITTABLE_STATUSES,
                $notAdmittable.' must never be confirmable as a final admission.'
            );
        }
    }

    public function test_the_new_admission_form_offers_only_admittable_applications(): void
    {
        // The dropdown is narrowed to the same allowlist the service enforces,
        // so an operator is never offered a choice that then fails validation.
        $college = $this->makeCollege('GATEF');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.create']);
        $applicant = $this->makeApplicant($college);

        $approved = $this->makeApplication($college, $applicant, null, null, 'approved');
        $submitted = $this->makeApplication($college, $applicant, null, null, 'submitted');
        $review = $this->makeApplication($college, $applicant, null, null, 'under_review');

        $response = $this->asCollege($college, $admin)->get(route('admissions.create'))->assertOk();

        $response->assertSee($approved->application_number);
        $response->assertDontSee($submitted->application_number);
        $response->assertDontSee($review->application_number);
    }

    public function test_the_admission_and_student_conversion_gates_agree(): void
    {
        // AdmissionService::ADMITTABLE_STATUSES and
        // ConvertApplicationToStudent::CONVERTIBLE are deliberately the same
        // vocabulary. This proves they agree BEHAVIOURALLY: a `submitted`
        // application can neither be confirmed as an admission nor converted
        // straight to a student. Both post-approval steps stay in lockstep.
        $college = $this->makeCollege('GATEG');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'admissions.create',
            'students.view', 'students.create',
            'student_enrollments.view', 'student_enrollments.create',
        ]);
        $application = $this->makeApplication($college, $this->makeApplicant($college), null, null, 'submitted');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application), [], ['Referer' => route('students.index')])
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, $this->admissionCount($college));
    }

    // =====================================================================
    // Defect 2 — the dead `admission.admission_create` approval action
    // =====================================================================

    public function test_admission_create_is_no_longer_a_supported_approval_action(): void
    {
        $this->assertFalse(ApprovalRegistry::isSupported('admission', 'admission_create'));
        $this->assertNull(ApprovalRegistry::labelFor('admission', 'admission_create'));
        $this->assertNull(ApprovalRegistry::permissionFor('admission', 'admission_create'));
        $this->assertNotContains('admission_create', ApprovalRegistry::actionsFor('admission'));
    }

    public function test_an_approval_request_can_no_longer_be_raised_for_the_removed_action(): void
    {
        // The registry is the allowlist that keeps target_type from being an
        // arbitrary class. With the entry gone, submitRequest() must refuse —
        // this is what makes the removed Settings toggle genuinely dead rather
        // than merely hidden.
        $college = $this->makeCollege('GATEH');
        $user = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve']);

        $this->expectException(ValidationException::class);

        app(ApprovalService::class)->submitRequest([
            'module' => 'admission',
            'action' => 'admission_create',
            'title' => 'Should never be created',
        ], $college->id, $user->getKey());
    }

    public function test_the_two_live_admission_approval_actions_survive(): void
    {
        // Guards against over-removal: only the dead entry was deleted.
        $this->assertTrue(ApprovalRegistry::isSupported('admission', 'application_submit'));
        $this->assertTrue(ApprovalRegistry::isSupported('admission', 'application_status_change'));

        $this->assertSame('New Admission Application', ApprovalRegistry::labelFor('admission', 'application_submit'));
        $this->assertSame('Application Status Change', ApprovalRegistry::labelFor('admission', 'application_status_change'));

        $this->assertSame(2, count(ApprovalRegistry::actionsFor('admission')));
    }

    public function test_every_other_module_approval_action_is_untouched(): void
    {
        $expected = [
            'student' => ['student_data_change', 'student_transfer'],
            'fees' => ['fee_concession', 'fee_refund'],
            'certificates' => ['certificate_issue'],
            'academics' => ['timetable_change'],
            'library' => ['book_issue'],
        ];

        foreach ($expected as $module => $actions) {
            foreach ($actions as $action) {
                $this->assertTrue(
                    ApprovalRegistry::isSupported($module, $action),
                    $module.'.'.$action.' must remain a supported approval action.'
                );
            }
        }

        $this->assertSame(
            ['admission', 'student', 'fees', 'certificates', 'academics', 'library'],
            ApprovalRegistry::modulesList()
        );
    }

    public function test_the_approval_settings_screen_no_longer_advertises_the_dead_toggle(): void
    {
        $college = $this->makeCollege('GATEI');
        $admin = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.settings.manage']);

        $response = $this->asCollege($college, $admin)->get(route('approvals.settings.index'))->assertOk();

        // The no-op row is gone — label and action key both.
        $response->assertDontSee('Create Admission');
        $response->assertDontSee('admission_create');

        // The two real admission actions are still configurable.
        $response->assertSee('New Admission Application');
        $response->assertSee('application_submit');
        $response->assertSee('Application Status Change');
        $response->assertSee('application_status_change');
    }
}
