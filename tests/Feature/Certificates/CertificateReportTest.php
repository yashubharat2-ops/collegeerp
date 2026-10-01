<?php

namespace Tests\Feature\Certificates;

use App\Http\Controllers\CertificateReportController;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\CertificateType;
use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Certificates\CertificateCatalog;
use App\Services\Certificates\CertificateWorkflow;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CertificateManagementSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class CertificateReportTest extends TestCase
{
    use StudentTestHelpers;

    private College $college;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = $this->makeCollege('CREP');
        $this->viewer = $this->makeUserWithPermissions($this->college, ['certificate_reports.view']);
        app(TenantContext::class)->set($this->college);
        app(CertificateCatalog::class)->provision($this->college->id);
    }

    private function createWorkflowCertificate(
        College $college,
        CertificateType $type,
        StudentEnrollment $enrollment,
        string $targetStatus = 'requested',
        int $verifyTimes = 0,
        ?string $purpose = 'Scholarship Application',
        ?Carbon $timestamp = null,
    ): Certificate {
        app(TenantContext::class)->set($college);
        $workflow = app(CertificateWorkflow::class);

        $certificate = $workflow->request([
            'certificate_type_id' => $type->id,
            'student_enrollment_id' => $enrollment->id,
            'purpose' => $purpose,
        ]);

        if (in_array($targetStatus, ['generated', 'issued'], true)) {
            $template = CertificateTemplate::query()->firstOrCreate(
                ['certificate_type_id' => $type->id, 'name' => $type->code.' Standard Template'],
                ['body' => 'Certificate {{ certificate_number }} for {{ student_name }} ({{ enrollment_number }})']
            );
            $workflow->generate($certificate->id, $template->id);
        }

        if ($targetStatus === 'issued') {
            $workflow->issue($certificate->id);
        }

        $certificate = $certificate->fresh();

        for ($i = 0; $i < $verifyTimes; $i++) {
            $workflow->verify((string) $certificate->number);
        }

        $certificate = $certificate->fresh();

        if ($timestamp !== null) {
            Certificate::withoutGlobalScopes()->whereKey($certificate->id)->update([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'generated_at' => $certificate->generated_at ? $timestamp : null,
                'issued_at' => $certificate->issued_at ? $timestamp : null,
                'last_verified_at' => $certificate->last_verified_at ? $timestamp : null,
            ]);
            $certificate = $certificate->fresh();
        }

        return $certificate;
    }

    public function test_reports_constant_and_tabs_follow_exact_five_report_order(): void
    {
        $expected = [
            'requests' => 'Certificate Request Report',
            'issuance' => 'Certificate Issuance Report',
            'verification' => 'Certificate Verification Report',
            'types' => 'Certificate Type-wise Report',
            'summary' => 'Certificate Summary',
        ];

        $this->assertSame($expected, CertificateReportController::REPORTS);
        $this->assertSame(array_keys($expected), array_keys(CertificateReportController::REPORTS));

        $html = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index'))
            ->assertOk()
            ->getContent();

        $cursor = -1;
        foreach ($expected as $key => $label) {
            $href = route('certificate-reports.index', ['report' => $key]);
            $pos = strpos($html, $href);
            $this->assertNotFalse($pos, "Missing tab link for {$key} ({$label})");
            $this->assertStringContainsString($label, $html);
            $this->assertGreaterThan($cursor, $pos, "Tab {$label} is out of order.");
            $cursor = $pos;
        }

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('bg-indigo-600 text-white shadow-sm', $html);
    }

    public function test_rbac_gates_certificate_reports_on_certificate_reports_view_permission(): void
    {
        $this->get(route('certificate-reports.index'))->assertRedirect(route('login'));

        $unprivileged = $this->makeUserWithPermissions($this->college, ['certificates.view']);
        $this->asCollege($this->college, $unprivileged)
            ->get(route('certificate-reports.index'))
            ->assertForbidden();

        foreach (array_keys(CertificateReportController::REPORTS) as $reportKey) {
            $this->asCollege($this->college, $unprivileged)
                ->get(route('certificate-reports.index', ['report' => $reportKey]))
                ->assertForbidden();

            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', ['report' => $reportKey]))
                ->assertOk()
                ->assertSee(CertificateReportController::REPORTS[$reportKey]);
        }

        // Seeded super-admin and college-admin roles receive certificate_reports.view.
        $this->seed(CertificateManagementSeeder::class);
        $this->assertTrue(Permission::where('slug', 'certificate_reports.view')->exists());

        $superRole = Role::whereNull('college_id')->where('slug', 'super-admin')->first();
        if ($superRole) {
            $this->assertTrue($superRole->permissions()->where('slug', 'certificate_reports.view')->exists());
        }
    }

    public function test_sidebar_places_certificate_reports_under_reports_section(): void
    {
        $unprivileged = $this->makeUserWithPermissions($this->college, ['student_reports.view']);
        $hiddenSidebar = $this->asCollege($this->college, $unprivileged)
            ->get(route('dashboard'))
            ->getContent();
        $this->assertStringNotContainsString(route('certificate-reports.index'), $hiddenSidebar);

        $visibleHtml = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index'))
            ->assertOk()
            ->getContent();

        $reportsHeaderPos = strpos($visibleHtml, '>Reports</div>');
        $certReportsLinkPos = strpos($visibleHtml, route('certificate-reports.index'));
        $platformFooterPos = strrpos($visibleHtml, '>Administration / Settings</div>');

        $this->assertNotFalse($reportsHeaderPos);
        $this->assertNotFalse($certReportsLinkPos);
        $this->assertGreaterThan($reportsHeaderPos, $certReportsLinkPos);
        if ($platformFooterPos !== false) {
            $this->assertLessThan($platformFooterPos, $certReportsLinkPos);
        }
    }

    public function test_route_is_strictly_get_only_and_renders_no_mutating_or_export_actions(): void
    {
        $route = Route::getRoutes()->getByName('certificate-reports.index');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->asCollege($this->college, $this->viewer)
                ->{$verb}(route('certificate-reports.index'))
                ->assertStatus(405);
        }

        $html = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index'))
            ->assertOk()
            ->getContent();

        $mainStart = strpos($html, '<main');
        $mainHtml = $mainStart !== false ? substr($html, $mainStart) : $html;

        $this->assertStringNotContainsString('method="POST"', $mainHtml);
        $this->assertStringNotContainsString('method="PUT"', $mainHtml);
        $this->assertStringNotContainsString('method="DELETE"', $mainHtml);
        $this->assertStringNotContainsString('Export CSV', $mainHtml);
        $this->assertStringNotContainsString('Export PDF', $mainHtml);
    }

    public function test_reuses_existing_certificate_tables_without_duplicate_report_tables(): void
    {
        $this->assertTrue(Schema::hasTable('certificates'));
        $this->assertTrue(Schema::hasTable('certificate_types'));
        $this->assertTrue(Schema::hasTable('certificate_templates'));
        $this->assertFalse(Schema::hasTable('certificate_reports'));
        $this->assertFalse(Schema::hasTable('certificate_requests'));
        $this->assertFalse(Schema::hasTable('certificate_issuances'));
        $this->assertFalse(Schema::hasTable('certificate_verifications'));
    }

    public function test_tenant_isolation_across_all_five_certificate_reports(): void
    {
        $foreignCollege = $this->makeCollege('CREP-FOR');
        app(TenantContext::class)->set($foreignCollege);
        app(CertificateCatalog::class)->provision($foreignCollege->id);

        $foreignStudent = $this->makeStudent($foreignCollege, ['first_name' => 'Foreign', 'last_name' => 'SecretStudent', 'student_number' => 'FOR-STU-999']);
        $foreignYear = $this->makeYear($foreignCollege);
        $foreignProgram = $this->makeProgram($foreignCollege);
        $foreignEnrollment = $this->makeEnrollment($foreignCollege, $foreignStudent, $foreignYear, $foreignProgram, ['enrollment_number' => 'FOR-ENR-999']);
        $foreignCustomType = CertificateType::create(['code' => 'FORONLY', 'name' => 'Foreign Exclusive Type']);
        $foreignCert = $this->createWorkflowCertificate($foreignCollege, $foreignCustomType, $foreignEnrollment, 'issued', 2, 'Foreign Secret Purpose');

        app(TenantContext::class)->set($this->college);
        $localStudent = $this->makeStudent($this->college, ['first_name' => 'Local', 'last_name' => 'VisibleStudent', 'student_number' => 'LOC-STU-101']);
        $localYear = $this->makeYear($this->college);
        $localProgram = $this->makeProgram($this->college);
        $localEnrollment = $this->makeEnrollment($this->college, $localStudent, $localYear, $localProgram, ['enrollment_number' => 'LOC-ENR-101']);
        $localType = CertificateType::where('code', 'BON')->firstOrFail();
        $localCert = $this->createWorkflowCertificate($this->college, $localType, $localEnrollment, 'issued', 1, 'Local Scholarship Purpose');

        foreach (array_keys(CertificateReportController::REPORTS) as $reportKey) {
            $response = $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => $reportKey,
                    'certificate_type_id' => $foreignCustomType->id,
                ]))
                ->assertOk();

            $response->assertDontSee('Foreign Exclusive Type')
                ->assertDontSee('SecretStudent')
                ->assertDontSee('FOR-ENR-999')
                ->assertDontSee((string) $foreignCert->number);
        }

        // Without foreign filter, local data is visible and foreign data is never leaked.
        $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', ['report' => 'requests']))
            ->assertOk()
            ->assertSee('VisibleStudent')
            ->assertSee((string) $localCert->number)
            ->assertDontSee('SecretStudent')
            ->assertDontSee((string) $foreignCert->number);
    }

    private function extractTableHtml(string $html): string
    {
        if (preg_match('/<table\b.*?<\/table>/s', $html, $matches) === 1) {
            return $matches[0];
        }

        $this->fail('Expected report response to contain a <table> element.');
    }

    public function test_certificate_request_report_displays_details_and_filters(): void
    {
        $year = $this->makeYear($this->college, '2026', '2026-2027');
        $program = $this->makeProgram($this->college, 'BTECH');
        $program->update(['name' => 'B.Tech Computer Science']);

        $studentA = $this->makeStudent($this->college, ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'student_number' => 'STU-REQ-01']);
        $enrollmentA = $this->makeEnrollment($this->college, $studentA, $year, $program, ['enrollment_number' => 'ENR-REQ-01']);

        $studentB = $this->makeStudent($this->college, ['first_name' => 'Meera', 'last_name' => 'Nair', 'student_number' => 'STU-REQ-02']);
        $enrollmentB = $this->makeEnrollment($this->college, $studentB, $year, $program, ['enrollment_number' => 'ENR-REQ-02']);

        $bonType = CertificateType::where('code', 'BON')->firstOrFail();
        $charType = CertificateType::where('code', 'CHAR')->firstOrFail();

        $reqCert = $this->createWorkflowCertificate(
            $this->college,
            $bonType,
            $enrollmentA,
            'requested',
            0,
            'Bank Education Loan',
            Carbon::parse('2026-09-05 10:00:00')
        );

        $issuedCert = $this->createWorkflowCertificate(
            $this->college,
            $charType,
            $enrollmentB,
            'issued',
            0,
            'Passport Verification',
            Carbon::parse('2026-09-20 10:00:00')
        );

        // Unfiltered view shows both requests with enrollment & academic year details
        $unfilteredTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', ['report' => 'requests']))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('#'.$reqCert->id, $unfilteredTable);
        $this->assertStringContainsString('Aarav Sharma', $unfilteredTable);
        $this->assertStringContainsString('ENR-REQ-01', $unfilteredTable);
        $this->assertStringContainsString('2026-2027', $unfilteredTable);
        $this->assertStringContainsString('B.Tech Computer Science', $unfilteredTable);
        $this->assertStringContainsString('Bonafide Certificate', $unfilteredTable);
        $this->assertStringContainsString('Meera Nair', $unfilteredTable);
        $this->assertStringContainsString((string) $issuedCert->number, $unfilteredTable);

        // Filter by certificate_type_id
        $byTypeTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'requests',
                    'certificate_type_id' => $bonType->id,
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Aarav Sharma', $byTypeTable);
        $this->assertStringNotContainsString('Meera Nair', $byTypeTable);

        // Filter by status
        $byStatusTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'requests',
                    'status' => 'issued',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Meera Nair', $byStatusTable);
        $this->assertStringContainsString((string) $issuedCert->number, $byStatusTable);
        $this->assertStringNotContainsString('Aarav Sharma', $byStatusTable);

        // Filter by date range
        $byDateTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'requests',
                    'from' => '2026-09-01',
                    'to' => '2026-09-10',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Aarav Sharma', $byDateTable);
        $this->assertStringNotContainsString('Meera Nair', $byDateTable);

        // Filter by search
        $bySearchTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'requests',
                    'search' => 'ENR-REQ-02',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Meera Nair', $bySearchTable);
        $this->assertStringNotContainsString('Aarav Sharma', $bySearchTable);
    }

    public function test_certificate_issuance_report_displays_issued_certificates_and_filters(): void
    {
        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);

        $studentPending = $this->makeStudent($this->college, ['first_name' => 'Pending', 'last_name' => 'Candidate']);
        $enrPending = $this->makeEnrollment($this->college, $studentPending, $year, $program);

        $studentIssued1 = $this->makeStudent($this->college, ['first_name' => 'Rohan', 'last_name' => 'Kulkarni']);
        $enrIssued1 = $this->makeEnrollment($this->college, $studentIssued1, $year, $program);

        $studentIssued2 = $this->makeStudent($this->college, ['first_name' => 'Divya', 'last_name' => 'Menon']);
        $enrIssued2 = $this->makeEnrollment($this->college, $studentIssued2, $year, $program);

        $bonType = CertificateType::where('code', 'BON')->firstOrFail();
        $migType = CertificateType::where('code', 'MIG')->firstOrFail();

        $this->createWorkflowCertificate($this->college, $bonType, $enrPending, 'requested');

        $issued1 = $this->createWorkflowCertificate(
            $this->college,
            $bonType,
            $enrIssued1,
            'issued',
            0,
            'Internship Proof',
            Carbon::parse('2026-08-10 12:00:00')
        );

        $issued2 = $this->createWorkflowCertificate(
            $this->college,
            $migType,
            $enrIssued2,
            'issued',
            1,
            'University Transfer',
            Carbon::parse('2026-09-15 12:00:00')
        );

        // Issuance report lists only issued certificates in the report table
        $unfilteredTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', ['report' => 'issuance']))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString((string) $issued1->number, $unfilteredTable);
        $this->assertStringContainsString('Rohan Kulkarni', $unfilteredTable);
        $this->assertStringContainsString((string) $issued2->number, $unfilteredTable);
        $this->assertStringContainsString('Divya Menon', $unfilteredTable);
        $this->assertStringNotContainsString('Pending Candidate', $unfilteredTable);

        // Filter by certificate_type_id
        $byTypeTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'issuance',
                    'certificate_type_id' => $migType->id,
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Divya Menon', $byTypeTable);
        $this->assertStringNotContainsString('Rohan Kulkarni', $byTypeTable);

        // Filter by date range and search
        $byDateSearchTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'issuance',
                    'from' => '2026-08-01',
                    'to' => '2026-08-31',
                    'search' => 'Rohan',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString((string) $issued1->number, $byDateSearchTable);
        $this->assertStringNotContainsString((string) $issued2->number, $byDateSearchTable);
    }

    public function test_certificate_verification_report_displays_verification_status_and_filters(): void
    {
        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);

        $studentVerified = $this->makeStudent($this->college, ['first_name' => 'Vikram', 'last_name' => 'Rao']);
        $enrVerified = $this->makeEnrollment($this->college, $studentVerified, $year, $program);

        $studentUnverified = $this->makeStudent($this->college, ['first_name' => 'Sneha', 'last_name' => 'Iyer']);
        $enrUnverified = $this->makeEnrollment($this->college, $studentUnverified, $year, $program);

        $bonType = CertificateType::where('code', 'BON')->firstOrFail();
        $provType = CertificateType::where('code', 'PROV')->firstOrFail();

        $verifiedCert = $this->createWorkflowCertificate(
            $this->college,
            $bonType,
            $enrVerified,
            'issued',
            3,
            'Embassy Verification',
            Carbon::parse('2026-09-18 14:30:00')
        );

        $unverifiedCert = $this->createWorkflowCertificate(
            $this->college,
            $provType,
            $enrUnverified,
            'issued',
            0,
            'Job Joining',
            Carbon::parse('2026-09-02 09:00:00')
        );

        // Verification report shows both and their verification status/count in the report table
        $unfilteredTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', ['report' => 'verification']))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString((string) $verifiedCert->number, $unfilteredTable);
        $this->assertStringContainsString('Vikram Rao', $unfilteredTable);
        $this->assertStringContainsString('Verified', $unfilteredTable);
        $this->assertStringContainsString((string) $unverifiedCert->number, $unfilteredTable);
        $this->assertStringContainsString('Sneha Iyer', $unfilteredTable);
        $this->assertStringContainsString('Unverified', $unfilteredTable);

        // Filter by verification_status = verified
        $verifiedOnlyTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'verification',
                    'verification_status' => 'verified',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Vikram Rao', $verifiedOnlyTable);
        $this->assertStringNotContainsString('Sneha Iyer', $verifiedOnlyTable);

        // Filter by verification_status = unverified
        $unverifiedOnlyTable = $this->extractTableHtml(
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', [
                    'report' => 'verification',
                    'verification_status' => 'unverified',
                ]))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('Sneha Iyer', $unverifiedOnlyTable);
        $this->assertStringNotContainsString('Vikram Rao', $unverifiedOnlyTable);
    }

    public function test_certificate_type_wise_report_aggregates_counts_by_existing_certificate_type(): void
    {
        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);
        $student = $this->makeStudent($this->college);
        $enrollment = $this->makeEnrollment($this->college, $student, $year, $program);

        $bonType = CertificateType::where('code', 'BON')->firstOrFail();
        $charType = CertificateType::where('code', 'CHAR')->firstOrFail();

        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'requested');
        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'generated');
        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'issued', 2);
        $this->createWorkflowCertificate($this->college, $charType, $enrollment, 'issued', 0);

        $response = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', ['report' => 'types']))
            ->assertOk();

        /** @var Collection<int, CertificateType> $typeRows */
        $typeRows = $response->viewData('typeRows');
        $bonRow = $typeRows->firstWhere('code', 'BON');
        $charRow = $typeRows->firstWhere('code', 'CHAR');

        $this->assertNotNull($bonRow);
        $this->assertSame(3, (int) $bonRow->total_count);
        $this->assertSame(1, (int) $bonRow->requested_count);
        $this->assertSame(1, (int) $bonRow->generated_count);
        $this->assertSame(1, (int) $bonRow->issued_count);
        $this->assertSame(1, (int) $bonRow->verified_count);
        $this->assertSame(2, (int) $bonRow->verification_lookups_sum);

        $this->assertNotNull($charRow);
        $this->assertSame(1, (int) $charRow->total_count);
        $this->assertSame(0, (int) $charRow->requested_count);
        $this->assertSame(0, (int) $charRow->generated_count);
        $this->assertSame(1, (int) $charRow->issued_count);
        $this->assertSame(0, (int) $charRow->verified_count);
    }

    public function test_certificate_summary_report_computes_totals_and_type_breakdown_with_date_filter(): void
    {
        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);
        $student = $this->makeStudent($this->college);
        $enrollment = $this->makeEnrollment($this->college, $student, $year, $program);

        $bonType = CertificateType::where('code', 'BON')->firstOrFail();

        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'requested', 0, 'Old Req', Carbon::parse('2026-07-10 10:00:00'));
        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'generated', 0, 'Recent Gen', Carbon::parse('2026-09-12 10:00:00'));
        $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'issued', 2, 'Recent Iss', Carbon::parse('2026-09-15 10:00:00'));

        $allResponse = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', ['report' => 'summary']))
            ->assertOk();

        $summary = $allResponse->viewData('summary');
        $this->assertSame(3, $summary['total_requests']);
        $this->assertSame(1, $summary['pending_requests']);
        $this->assertSame(1, $summary['generated_certificates']);
        $this->assertSame(1, $summary['total_issued']);
        $this->assertSame(1, $summary['total_verified']);
        $this->assertSame(2, $summary['total_verification_lookups']);

        $filteredResponse = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', [
                'report' => 'summary',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]))
            ->assertOk();

        $filteredSummary = $filteredResponse->viewData('summary');
        $this->assertSame(2, $filteredSummary['total_requests']);
        $this->assertSame(0, $filteredSummary['pending_requests']);
        $this->assertSame(1, $filteredSummary['generated_certificates']);
        $this->assertSame(1, $filteredSummary['total_issued']);
        $this->assertSame(1, $filteredSummary['total_verified']);
    }

    public function test_empty_states_and_pagination_preserve_query_parameters(): void
    {
        foreach (['requests', 'issuance', 'verification'] as $reportKey) {
            $this->asCollege($this->college, $this->viewer)
                ->get(route('certificate-reports.index', ['report' => $reportKey]))
                ->assertOk()
                ->assertSee('match the selected filters');
        }

        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);
        $student = $this->makeStudent($this->college);
        $enrollment = $this->makeEnrollment($this->college, $student, $year, $program);
        $bonType = CertificateType::where('code', 'BON')->firstOrFail();

        for ($i = 0; $i < 18; $i++) {
            $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'requested', 0, "Request #{$i}");
        }

        $page = $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', [
                'report' => 'requests',
                'certificate_type_id' => $bonType->id,
            ]))
            ->assertOk();

        $paginator = $page->viewData('certificates');
        $this->assertSame(18, $paginator->total());
        $nextUrl = (string) $paginator->nextPageUrl();
        $this->assertStringContainsString('report=requests', $nextUrl);
        $this->assertStringContainsString('certificate_type_id='.$bonType->id, $nextUrl);
    }

    public function test_reports_avoid_n_plus_one_queries(): void
    {
        $this->asCollege($this->college, $this->viewer);

        $year = $this->makeYear($this->college);
        $program = $this->makeProgram($this->college);
        $bonType = CertificateType::where('code', 'BON')->firstOrFail();

        for ($i = 0; $i < 2; $i++) {
            $student = $this->makeStudent($this->college, ['first_name' => "Baseline{$i}", 'last_name' => 'Student']);
            $enrollment = $this->makeEnrollment($this->college, $student, $year, $program);
            $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'issued', 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', ['report' => 'requests']))
            ->assertOk();
        $baselineCount = count(DB::getQueryLog());

        for ($i = 0; $i < 10; $i++) {
            $student = $this->makeStudent($this->college, ['first_name' => "Extra{$i}", 'last_name' => 'Student']);
            $enrollment = $this->makeEnrollment($this->college, $student, $year, $program);
            $this->createWorkflowCertificate($this->college, $bonType, $enrollment, 'issued', 1);
        }

        DB::flushQueryLog();
        $this->asCollege($this->college, $this->viewer)
            ->get(route('certificate-reports.index', ['report' => 'requests']))
            ->assertOk();
        $expandedCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baselineCount, $expandedCount, 'Certificate Request Report should not execute extra queries as row count grows.');
    }
}
