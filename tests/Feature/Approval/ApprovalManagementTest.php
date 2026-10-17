<?php

namespace Tests\Feature\Approval;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\ApprovalRequest;
use App\Models\College;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ApprovalManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeYear(College $college, string $code = '2026'): AcademicYear
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

    private function makeProgram(College $college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeApplicant(College $college): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Test',
            'last_name' => 'Applicant',
            'status' => 'active',
        ]);
    }

    // === ADMISSION SIDEBAR ===

    public function test_admission_sidebar_follows_approved_hierarchy(): void
    {
        $college = $this->makeCollege('ADSBH');
        $user = $this->makeUserWithPermissions($college, [\App\Models\Permission::query()->pluck('slug')->all()]);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $aside = $this->aside($html);

        $this->assertStringContainsString('nav-group__label">Admission<', $aside, 'Admission group must exist with correct label');
        $this->assertStringContainsString('data-nav-group="admission"', $aside);
        $this->assertStringContainsString('data-nav-group="approval-management"', $aside, 'Approval Management must be central menu');
        $this->assertStringNotContainsString('data-nav-group="admissions"', $aside, 'Old plural id must be gone');
    }

    public function test_reports_appears_exactly_once_and_last(): void
    {
        $college = $this->makeCollege('ADSBR');
        $user = $this->makeUserWithPermissions($college, \App\Models\Permission::query()->pluck('slug')->all());
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $aside = $this->aside($html);

        // Reports exactly once in sidebar (admission reports)
        $this->assertSame(1, substr_count($aside, '>Reports<'), 'Reports should appear as last direct item in Admission');
        // Find admission group and ensure Reports is last link
        $admissionBlock = $this->group($aside, 'Admission');
        $hrefs = $this->hrefs($admissionBlock);
        $last = end($hrefs);
        $this->assertStringContainsString(parse_url(route('admission-reports.index'), PHP_URL_PATH), $last, 'Reports must be last in Admission');
    }

    public function test_admission_reports_require_permission(): void
    {
        $college = $this->makeCollege('ADREPR');
        $noPerm = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $noPerm)->get(route('admission-reports.index'))->assertForbidden();

        $withPerm = $this->makeUserWithPermissions($college, ['admission_reports.view']);
        $this->asCollege($college, $withPerm)->get(route('admission-reports.index'))->assertOk();
    }

    public function test_report_filters_return_correct_records(): void
    {
        $college = $this->makeCollege('ADREPF');
        $admin = $this->makeUserWithPermissions($college, ['admission_reports.view', 'admission_applications.view']);
        $applicant = $this->makeApplicant($college);
        $year1 = $this->makeYear($college, '2026');
        $year2 = $this->makeYear($college, '2027');
        $prog1 = $this->makeProgram($college, 'BSC');
        $prog2 = $this->makeProgram($college, 'MSC');

        $app1 = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year1->id,'program_id'=>$prog1->id,'application_number'=>'APP-REP1','status'=>'approved']);
        $app2 = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year2->id,'program_id'=>$prog2->id,'application_number'=>'APP-REP2','status'=>'rejected']);

        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['academic_year_id'=>$year1->id]))->assertOk()->assertSee('APP-REP1')->assertDontSee('APP-REP2');
        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['program_id'=>$prog2->id]))->assertOk()->assertSee('APP-REP2')->assertDontSee('APP-REP1');
        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['status'=>'approved']))->assertOk()->assertSee('APP-REP1')->assertDontSee('APP-REP2');
    }

    public function test_tenant_isolation_for_reports(): void
    {
        $collegeA = $this->makeCollege('ADREPA');
        $collegeB = $this->makeCollege('ADREPB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_reports.view']);
        $applicantA = $this->makeApplicant($collegeA);
        $applicantB = $this->makeApplicant($collegeB);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$applicantA->id,'application_number'=>'APP-A-ISO','status'=>'approved']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$applicantB->id,'application_number'=>'APP-B-ISO','status'=>'approved']);

        $resp = $this->asCollege($collegeA, $adminA)->get(route('admission-reports.index'));
        $resp->assertOk()->assertSee('APP-A-ISO')->assertDontSee('APP-B-ISO');
    }

    // === CENTRAL APPROVAL ===

    public function test_authorized_users_can_submit_requests(): void
    {
        $college = $this->makeCollege('APPR1');
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create']);
        // staff lacks update permission so approval required
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create','admission_applications.update','approvals.view','approvals.approve']);

        // Ensure approval required setting
        \App\Models\ApprovalSetting::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','is_required'=>true,'label'=>'test']);

        $this->asCollege($college, $staff)->post(route('admission-applications.store'), [
            'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'status'=>'submitted','remarks'=>'test'
        ])->assertRedirect(route('admission-applications.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applications', ['college_id'=>$college->id,'applicant_id'=>$applicant->id,'status'=>'submitted']);
        $this->assertDatabaseHas('approval_requests', ['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','status'=>'pending','requested_by'=>$staff->id]);
    }

    public function test_pending_requests_appear_to_authorized_approvers(): void
    {
        $college = $this->makeCollege('APPR2');
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create']);
        $approver = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create','admission_applications.update','approvals.view','approvals.approve']);
        \App\Models\ApprovalSetting::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','is_required'=>true,'label'=>'test']);

        $this->asCollege($college, $staff)->post(route('admission-applications.store'), [
            'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'status'=>'submitted'
        ]);

        $this->asCollege($college, $approver)->get(route('approvals.pending'))->assertOk()->assertSee('APR-');
    }

    public function test_unauthorized_users_cannot_view_restricted_requests(): void
    {
        $college = $this->makeCollege('APPR3');
        $user = $this->makeUserWithPermissions($college, []); // no approvals permission
        // Pending is filter-empty for non-approvers, not forbidden (they simply see no approvals)
        $this->asCollege($college, $user)->get(route('approvals.pending'))->assertOk();
        // History is restricted to approvers/managers
        $this->asCollege($college, $user)->get(route('approvals.history'))->assertForbidden();
        // My Requests is personal and accessible to any active user (even without approvals.view)
        $this->asCollege($college, $user)->get(route('approvals.my-requests'))->assertOk();
    }

    public function test_users_cannot_approve_own_requests(): void
    {
        $college = $this->makeCollege('APPR4');
        $user = $this->makeUserWithPermissions($college, ['admission_applications.view','approvals.view','approvals.approve','admission_applications.update']);
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'application_number'=>'APP-OWN','status'=>'submitted']);

        $req = ApprovalRequest::withoutGlobalScopes()->create([
            'college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-OWN','title'=>'test','status'=>'pending','requested_by'=>$user->id
        ]);

        $this->asCollege($college, $user)->post(route('approvals.approve', $req))->assertSessionHasErrors();
        $req->refresh();
        $this->assertSame('pending', $req->status);
    }

    public function test_admin_can_approve_eligible_requests(): void
    {
        $college = $this->makeCollege('APPR5');
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create']);
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update','approvals.view','approvals.approve']);
        \App\Models\ApprovalSetting::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','is_required'=>true]);

        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'application_number'=>'APP-APRV','status'=>'submitted']);
        $req = \App\Models\ApprovalRequest::withoutGlobalScopes()->create([
            'college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-APRV','title'=>'test','status'=>'pending','requested_by'=>$staff->id,'payload'=>['status'=>'approved']
        ]);

        $this->asCollege($college, $admin)->post(route('approvals.approve', $req), ['decision_reason'=>'looks good'])->assertRedirect();
        $req->refresh();
        $this->assertSame('approved', $req->status);
        $app->refresh();
        $this->assertSame('approved', $app->status);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $college = $this->makeCollege('APPR6');
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update','approvals.view','approvals.approve']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'application_number'=>'APP-REJ','status'=>'submitted']);
        $req = ApprovalRequest::withoutGlobalScopes()->create([
            'college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-REJ','title'=>'test','status'=>'pending','requested_by'=>$staff->id
        ]);

        $this->asCollege($college, $admin)->post(route('approvals.reject', $req), [])->assertSessionHasErrors('decision_reason');
        $this->asCollege($college, $admin)->post(route('approvals.reject', $req), ['decision_reason'=>'Missing docs'])->assertRedirect();
        $req->refresh();
        $this->assertSame('rejected', $req->status);
        $this->assertSame('Missing docs', $req->decision_reason);
        $app->refresh();
        $this->assertSame('rejected', $app->status);
    }

    public function test_approval_history_recorded(): void
    {
        $college = $this->makeCollege('APPR7');
        $staff = $this->makeUserWithPermissions($college, []);
        $admin = $this->makeUserWithPermissions($college, ['approvals.view','approvals.approve','admission_applications.update']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$this->makeApplicant($college)->id,'application_number'=>'APP-HIST','status'=>'submitted']);
        ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-HIST1','title'=>'t','status'=>'approved','requested_by'=>$staff->id,'decided_by'=>$admin->id,'decided_at'=>now(),'decision_reason'=>'ok']);
        ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-HIST2','title'=>'t','status'=>'rejected','requested_by'=>$staff->id,'decided_by'=>$admin->id,'decided_at'=>now(),'decision_reason'=>'bad']);

        $this->asCollege($college, $admin)->get(route('approvals.history'))->assertOk()->assertSee('APR-HIST1')->assertSee('APR-HIST2');
    }

    public function test_duplicate_decision_prevented(): void
    {
        $college = $this->makeCollege('APPR8');
        $staff = $this->makeUserWithPermissions($college, []);
        $admin = $this->makeUserWithPermissions($college, ['approvals.view','approvals.approve','admission_applications.update']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$this->makeApplicant($college)->id,'application_number'=>'APP-DUP','status'=>'submitted']);
        $req = ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-DUP','title'=>'t','status'=>'pending','requested_by'=>$staff->id]);

        $this->asCollege($college, $admin)->post(route('approvals.approve', $req), ['decision_reason'=>'ok'])->assertRedirect();
        $this->asCollege($college, $admin)->post(route('approvals.approve', $req), [])->assertSessionHasErrors();
    }

    public function test_tenant_isolation_enforced(): void
    {
        $collegeA = $this->makeCollege('APPR9A');
        $collegeB = $this->makeCollege('APPR9B');
        $staffA = $this->makeUserWithPermissions($collegeA, []);
        $adminB = $this->makeUserWithPermissions($collegeB, ['approvals.view','approvals.approve','admission_applications.update']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$this->makeApplicant($collegeA)->id,'application_number'=>'APP-TEN','status'=>'submitted']);
        $req = ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-TEN','title'=>'t','status'=>'pending','requested_by'=>$staffA->id]);

        $this->asCollege($collegeB, $adminB)->post(route('approvals.approve', $req))->assertStatus(404);
    }

    public function test_direct_http_cannot_bypass(): void
    {
        $college = $this->makeCollege('APPR10');
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update']);
        // staff has update permission but lacks approvals.approve, so cannot bypass approval
        $staffNoApprove = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update']);
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        \App\Models\ApprovalSetting::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_status_change','is_required'=>true]);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'application_number'=>'APP-BYP','status'=>'submitted']);

        // Try direct update to approved without approval flow
        $this->asCollege($college, $staffNoApprove)->put(route('admission-applications.update', $app), [
            'academic_year_id'=>$year->id,'program_id'=>$prog->id,'status'=>'approved'
        ])->assertSessionHasNoErrors(); // It goes via approval request instead

        $app->refresh();
        $this->assertSame('submitted', $app->status, 'Status should not have changed directly');
        $this->assertDatabaseHas('approval_requests', ['target_id'=>$app->id,'module'=>'admission','action'=>'application_status_change','status'=>'pending']);
    }

    public function test_approval_settings_cannot_be_modified_by_unauthorized(): void
    {
        $college = $this->makeCollege('APPR11');
        $staff = $this->makeUserWithPermissions($college, ['approvals.view']);
        $admin = $this->makeUserWithPermissions($college, ['approvals.view','approvals.settings.manage']);
        $this->asCollege($college, $staff)->get(route('approvals.settings.index'))->assertForbidden();
        $this->asCollege($college, $staff)->put(route('approvals.settings.update'), ['settings'=>[]])->assertForbidden();
        $this->asCollege($college, $admin)->get(route('approvals.settings.index'))->assertOk();
    }

    // === ADMISSION WORKFLOW INTEGRATION ===

    public function test_application_submission_creates_request(): void
    {
        $college = $this->makeCollege('ADWF1');
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $staff = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.create']);
        \App\Models\ApprovalSetting::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','is_required'=>true]);
        $this->asCollege($college, $staff)->post(route('admission-applications.store'), ['applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'status'=>'submitted'])->assertRedirect();
        $this->assertDatabaseHas('approval_requests', ['college_id'=>$college->id,'status'=>'pending']);
    }

    public function test_pending_not_confirmed(): void
    {
        $college = $this->makeCollege('ADWF2');
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$this->makeApplicant($college)->id,'application_number'=>'APP-PEND','status'=>'submitted']);
        // Pending approval should not create admission automatically
        $this->assertDatabaseMissing('admissions', ['application_id'=>$app->id]);
    }

    public function test_approval_advances_correctly(): void
    {
        $college = $this->makeCollege('ADWF3');
        $staff = $this->makeUserWithPermissions($college, []);
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update','approvals.view','approvals.approve']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$this->makeApplicant($college)->id,'application_number'=>'APP-ADV','status'=>'submitted','academic_year_id'=>$this->makeYear($college)->id,'program_id'=>$this->makeProgram($college)->id]);
        $req = ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-ADV','title'=>'t','status'=>'pending','requested_by'=>$staff->id,'payload'=>['status'=>'approved']]);
        $this->asCollege($college, $admin)->post(route('approvals.approve', $req));
        $app->refresh();
        $this->assertSame('approved', $app->status);
    }

    public function test_rejection_preserves_record(): void
    {
        $college = $this->makeCollege('ADWF4');
        $staff = $this->makeUserWithPermissions($college, []);
        $admin = $this->makeUserWithPermissions($college, ['approvals.view','approvals.approve','admission_applications.update']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$this->makeApplicant($college)->id,'application_number'=>'APP-REJP','status'=>'submitted']);
        $req = ApprovalRequest::withoutGlobalScopes()->create(['college_id'=>$college->id,'module'=>'admission','action'=>'application_submit','target_type'=>'admission.application_submit','target_id'=>$app->id,'reference_number'=>'APR-REJP','title'=>'t','status'=>'pending','requested_by'=>$staff->id]);
        $this->asCollege($college, $admin)->post(route('approvals.reject', $req), ['decision_reason'=>'incomplete']);
        $app->refresh();
        $this->assertSame('rejected', $app->status);
        $this->assertDatabaseHas('admission_applications', ['id'=>$app->id]);
    }

    // === HELPERS ===
    private function aside(string $html): string
    {
        $start = strpos($html, '<aside');
        $end = strpos($html, '</aside>', $start);
        return substr($html, $start, $end - $start);
    }
    private function group(string $aside, string $label): string
    {
        $start = strpos($aside, 'nav-group__label">'.$label.'<');
        $next = strpos($aside, 'nav-group__label">', $start+1);
        return substr($aside, $start, $next===false?null:$next-$start);
    }
    private function hrefs(string $markup): array
    {
        preg_match_all('/<a class="nav-link" href="([^"]+)"/', $markup, $m);
        return array_map(fn($h)=>parse_url($h, PHP_URL_PATH), $m[1]);
    }
}
