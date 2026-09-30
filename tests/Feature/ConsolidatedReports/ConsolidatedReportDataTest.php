<?php

namespace Tests\Feature\ConsolidatedReports;

use App\Domain\Communication\Services\CommunicationReportService;
use App\Domain\Finance\Services\FinanceReportService;
use App\Domain\HR\Services\HrReportService;
use App\Domain\Hostel\Services\HostelReportService;
use App\Domain\Inventory\Services\InventoryReportService;
use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Library\Services\LibraryReportService;
use App\Domain\Transport\Services\TransportReportService;
use App\Models\College;
use App\Models\User;
use App\Services\Certificates\CertificateReportService;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

/**
 * Data tests for REPORTS → Consolidated Reports.
 *
 * The module owns no table: every figure asserted here is produced by the
 * report service of the module that owns the record (Student, Academic,
 * Examination, Finance, HR, Library, Transport, Hostel, Inventory,
 * Communication, Certificate). The tests therefore check both directions:
 * the consolidated screen shows the owning service's own figures, and it stays
 * tenant-scoped when another college's ids are forged into the query string.
 */
class ConsolidatedReportDataTest extends TestCase
{
    use ConsolidatedReportsTestHelpers;
    use StudentTestHelpers;

    private const VIEW = ['consolidated_reports.view'];

    /** @var array<string, string> */
    private const EXPECTED_REPORTS = [
        'dashboard' => 'College Dashboard Summary',
        'student_strength' => 'Student Strength Summary',
        'academic' => 'Academic Summary',
        'examination' => 'Examination Summary',
        'finance' => 'Fee / Finance Summary',
        'hr' => 'HR Summary',
        'library' => 'Library Summary',
        'transport' => 'Transport Summary',
        'hostel' => 'Hostel Summary',
        'inventory' => 'Inventory / Asset Summary',
        'communication' => 'Communication Summary',
        'certificate' => 'Certificate Summary',
        'management' => 'Management / MIS Reports',
    ];

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    /* ------------------------------------------------------------------ *\
     * College Dashboard Summary + Management / MIS Reports
     * \* ------------------------------------------------------------------ */

    public function test_the_college_dashboard_shows_one_headline_group_per_module(): void
    {
        $college = $this->makeCollege('CREPDASH');
        $this->makeConsolidatedWorld($college, 'DASH');
        $this->reporter($college);

        $page = $this->get(route('consolidated-reports.index'))->assertOk();

        $this->assertSame(2, $this->headlineMetric($page, 'students', 'Students'));
        $this->assertSame(2, $this->headlineMetric($page, 'students', 'Enrollments'));

        $this->assertSame(1, $this->headlineMetric($page, 'academics', 'Sections / batches'));
        $this->assertSame(2, $this->headlineMetric($page, 'academics', 'Active students in sections'));
        $this->assertSame(60, $this->headlineMetric($page, 'academics', 'Section capacity'));

        $this->assertSame(1, $this->headlineMetric($page, 'examinations', 'Examinations'));
        $this->assertSame(1, $this->headlineMetric($page, 'examinations', 'Published results'));
        $this->assertSame(1, $this->headlineMetric($page, 'examinations', 'Passed'));
        $this->assertSame(100.0, $this->headlineMetric($page, 'examinations', 'Pass rate'));

        $this->assertSame(25000.0, $this->headlineMetric($page, 'finance', 'Total fee value'));
        $this->assertSame(10000.0, $this->headlineMetric($page, 'finance', 'Net collected'));
        $this->assertSame(15000.0, $this->headlineMetric($page, 'finance', 'Outstanding'));
        $this->assertSame(1, $this->headlineMetric($page, 'finance', 'Collections'));

        $this->assertSame(1, $this->headlineMetric($page, 'hr', 'Staff records'));
        $this->assertSame(1, $this->headlineMetric($page, 'hr', 'Active staff'));
        $this->assertSame(0, $this->headlineMetric($page, 'hr', 'Payroll records'));
        $this->assertSame(0.0, $this->headlineMetric($page, 'hr', 'Net payroll'));

        $this->assertSame(1, $this->headlineMetric($page, 'library', 'Titles'));
        $this->assertSame(1, $this->headlineMetric($page, 'library', 'Copies'));
        $this->assertSame(1, $this->headlineMetric($page, 'library', 'Members'));
        $this->assertSame(1, $this->headlineMetric($page, 'library', 'On loan'));
        $this->assertSame(0, $this->headlineMetric($page, 'library', 'Overdue'));

        $this->assertSame(1, $this->headlineMetric($page, 'transport', 'Vehicles'));
        $this->assertSame(1, $this->headlineMetric($page, 'transport', 'Active vehicles'));
        $this->assertSame(1, $this->headlineMetric($page, 'transport', 'Routes'));
        $this->assertSame(1, $this->headlineMetric($page, 'transport', 'Stops'));
        $this->assertSame(1, $this->headlineMetric($page, 'transport', 'Active assignments'));

        $this->assertSame(1, $this->headlineMetric($page, 'hostel', 'Hostels'));
        $this->assertSame(1, $this->headlineMetric($page, 'hostel', 'Beds'));
        $this->assertSame(1, $this->headlineMetric($page, 'hostel', 'Occupied beds'));
        $this->assertSame(0, $this->headlineMetric($page, 'hostel', 'Available beds'));
        $this->assertSame(1, $this->headlineMetric($page, 'hostel', 'Active allocations'));

        $this->assertSame(2, $this->headlineMetric($page, 'inventory', 'Items / assets'));
        $this->assertSame(1, $this->headlineMetric($page, 'inventory', 'Assets'));
        $this->assertSame(1, $this->headlineMetric($page, 'inventory', 'Low-stock items'));
        $this->assertSame(0, $this->headlineMetric($page, 'inventory', 'Assets assigned'));
        $this->assertSame(0, $this->headlineMetric($page, 'inventory', 'Under maintenance'));

        $this->assertSame(1, $this->headlineMetric($page, 'communication', 'Notices'));
        $this->assertSame(0, $this->headlineMetric($page, 'communication', 'Circulars'));
        $this->assertSame(0, $this->headlineMetric($page, 'communication', 'Notifications'));
        $this->assertSame(1, $this->headlineMetric($page, 'communication', 'SMS / e-mail logs'));
        $this->assertSame(0, $this->headlineMetric($page, 'communication', 'Failed communications'));

        $this->assertSame(1, $this->headlineMetric($page, 'certificates', 'Requests'));
        $this->assertSame(1, $this->headlineMetric($page, 'certificates', 'Pending requests'));
        $this->assertSame(0, $this->headlineMetric($page, 'certificates', 'Issued'));
        $this->assertSame(0, $this->headlineMetric($page, 'certificates', 'Verified'));

        // Eleven modules, no more and no fewer.
        $this->assertCount(11, $page->viewData('summary')['groups']);
    }

    public function test_the_management_view_derives_indicators_and_attention_from_existing_figures(): void
    {
        $college = $this->makeCollege('CREPMIS');
        $this->makeConsolidatedWorld($college, 'MIS');
        $this->reporter($college);

        $page = $this->get(route('consolidated-reports.index', ['report' => 'management']))->assertOk();
        $summary = $page->viewData('summary');

        // The same headline figures as the College Dashboard Summary.
        $this->assertCount(11, $summary['groups']);
        $this->assertSame(2, $this->headlineMetric($page, 'students', 'Students'));

        // Indicators are arithmetic over two figures read live.
        $this->assertSame(40.0, $this->indicatorValue($page, 'Fee collection rate'));
        $this->assertSame(60.0, $this->indicatorValue($page, 'Outstanding fee share'));
        $this->assertSame(100.0, $this->indicatorValue($page, 'Hostel bed occupancy'));
        $this->assertSame(0.0, $this->indicatorValue($page, 'Library copy availability'));
        $this->assertSame(100.0, $this->indicatorValue($page, 'Result pass rate'));
        $this->assertSame(0.0, $this->indicatorValue($page, 'Staff attendance rate'));
        $this->assertSame(2.0, $this->indicatorValue($page, 'Students per active staff member'));

        // Attention carries the modules' own open figures.
        $this->assertSame(15000.0, $this->attentionValue($page, 'Finance', 'Outstanding fee balance'));
        $this->assertSame(1, $this->attentionValue($page, 'Inventory', 'Items at or below the low-stock threshold'));
        $this->assertSame(0, $this->attentionValue($page, 'Library', 'Overdue library issues'));

        $attention = collect($summary['attention']);
        $this->assertTrue($attention->firstWhere('label', 'Outstanding fee balance')['attention']);
        $this->assertFalse($attention->firstWhere('label', 'Overdue library issues')['attention']);
    }

    /* ------------------------------------------------------------------ *\
     * Student Strength / Academics / Examinations
     * \* ------------------------------------------------------------------ */

    public function test_the_student_academic_and_examination_summaries_read_the_existing_derivations(): void
    {
        $college = $this->makeCollege('CREPDERIVE');
        $this->makeConsolidatedWorld($college, 'DERIVE');
        $this->reporter($college);

        $this->get(route('consolidated-reports.index', ['report' => 'student_strength']))
            ->assertOk()
            ->assertViewHas('studentsCount', 2)
            ->assertViewHas('enrollmentsCount', 2)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        $this->get(route('consolidated-reports.index', ['report' => 'academic']))
            ->assertOk()
            ->assertViewHas('sections', fn (array $sections) => $sections['sectionsCount'] === 1
                && $sections['studentsCount'] === 2
                && $sections['capacityTotal'] === 60
                && $sections['rows']->total() === 1)
            ->assertViewHas('attendance', fn (array $attendance) => $attendance['counts'] === [
                'present' => 1,
                'absent' => 0,
                'late' => 0,
                'leave' => 0,
            ]);

        $this->get(route('consolidated-reports.index', ['report' => 'examination']))
            ->assertOk()
            ->assertViewHas('results', fn (array $results) => $results['totalResults'] === 1
                && $results['passCount'] === 1
                && $results['failCount'] === 0
                && $results['passRate'] === 100.0)
            ->assertViewHas('examinations', function (array $data): bool {
                $row = $data['rows']->first();

                return $data['rows']->total() === 1
                    && (int) $row->schedules_count === 1
                    && (int) $row->subjects_count === 1
                    && (int) $row->attendance_count === 1
                    && (int) $row->marks_count === 1
                    && (int) $row->results_count === 1
                    && (int) $row->published_count === 1
                    && (int) $row->pass_count === 1;
            });
    }

    public function test_the_student_strength_filters_follow_the_student_reports_vocabulary(): void
    {
        $college = $this->makeCollege('CREPSTRENGTH');
        $world = $this->makeConsolidatedWorld($college, 'STR');
        $this->reporter($college);

        // A third student whose student record is inactive is excluded by the
        // report's default status vocabulary, exactly like the Student Report.
        $inactive = $this->makeStudent($college, ['first_name' => 'Inactive', 'last_name' => 'STR', 'status' => 'inactive']);
        $this->makeEnrollment($college, $inactive, $world['year'], $world['program'], ['section_id' => $world['section']->id]);

        $this->get(route('consolidated-reports.index', ['report' => 'student_strength']))
            ->assertOk()
            ->assertViewHas('studentsCount', 2);

        $this->get(route('consolidated-reports.index', [
            'report' => 'student_strength',
            'student_status' => 'all',
            'enrollment_status' => 'all',
        ]))->assertOk()->assertViewHas('studentsCount', 3);

        $otherYear = $this->makeYear($college, 'AY-OTHER', '2027 Other', '2027-06-01', '2028-05-31');

        $this->get(route('consolidated-reports.index', [
            'report' => 'student_strength',
            'academic_year_id' => $world['year']->id,
        ]))->assertOk()->assertViewHas('studentsCount', 2);

        $this->get(route('consolidated-reports.index', [
            'report' => 'student_strength',
            'academic_year_id' => $otherYear->id,
        ]))->assertOk()
            ->assertViewHas('studentsCount', 0)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *\
     * Module summaries equal their owning report services
     * \* ------------------------------------------------------------------ */

    public function test_each_module_summary_is_the_array_its_owning_report_service_returns(): void
    {
        $college = $this->makeCollege('CREPOWN');
        $this->makeConsolidatedWorld($college, 'OWN');
        $this->reporter($college);

        $library = $this->withTenant($college, fn () => app(LibraryReportService::class)->summary());
        $this->assertSame(1, $library['books']['total'], 'The fixture must be visible to the Library Report service.');
        $this->get(route('consolidated-reports.index', ['report' => 'library']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $library);

        $transport = $this->withTenant($college, fn () => app(TransportReportService::class)->summary());
        $this->assertSame(1, $transport['vehicles']['total']);
        $this->get(route('consolidated-reports.index', ['report' => 'transport']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $transport);

        $hostel = $this->withTenant($college, fn () => app(HostelReportService::class)->summary()['summary']);
        $this->assertSame(1, $hostel['total_hostels']);
        $this->get(route('consolidated-reports.index', ['report' => 'hostel']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $hostel);

        $inventory = $this->withTenant(
            $college,
            fn () => app(InventoryReportService::class)->summary(InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD)
        );
        $this->assertSame(2, $inventory['metrics']['total_items']);
        $this->get(route('consolidated-reports.index', ['report' => 'inventory']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $inventory);

        $communication = $this->withTenant($college, fn () => app(CommunicationReportService::class)->summary());
        $this->assertSame(1, $communication['notices']['total']);
        $this->get(route('consolidated-reports.index', ['report' => 'communication']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $communication);

        $certificate = $this->withTenant($college, fn () => app(CertificateReportService::class)->summary([]));
        $this->assertSame(1, $certificate['total_requests']);
        $this->get(route('consolidated-reports.index', ['report' => 'certificate']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $certificate);

        $finance = $this->withTenant(
            $college,
            fn () => app(FinanceReportService::class)->summary(['academic_year_id' => null, 'program_id' => null])['summary']
        );
        $this->assertSame(1, $finance['collections']['payments']);
        $this->get(route('consolidated-reports.index', ['report' => 'finance']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $finance);

        $hr = $this->withTenant($college, fn () => app(HrReportService::class)->summary([])['summary']);
        $this->assertSame(1, $hr['staff']['total']);
        $this->get(route('consolidated-reports.index', ['report' => 'hr']))->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary == $hr);
    }

    /* ------------------------------------------------------------------ *\
     * Tenant isolation + forged foreign ids
     * \* ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        // College B owns a complete world; college A owns nothing at all.
        $b = $this->makeCollege('CREPTB');
        $worldB = $this->makeConsolidatedWorld($b, 'B');

        $a = $this->makeCollege('CREPTA');
        $this->reporter($a);

        // Every consolidated report is empty for college A — and stays empty
        // even when the request forges every id college B owns.
        $forged = [
            'academic_year_id' => $worldB['year']->id,
            'academic_term_id' => $worldB['term']->id,
            'program_id' => $worldB['program']->id,
            'section_id' => $worldB['section']->id,
            'examination_id' => $worldB['examination']->id,
            'department_id' => $worldB['department']->id,
            'certificate_type_id' => $worldB['certificateType']->id,
        ];

        foreach (array_keys(self::EXPECTED_REPORTS) as $report) {
            $this->assertScopedToNothing($report, []);
            $this->assertScopedToNothing($report, $forged);
        }

        // College B, with its own ids, sees its own world: the fixture (and the
        // tenant scoping above) is real, not an empty database.
        $reporterB = $this->makeUserWithPermissions($b, self::VIEW);
        $this->asCollege($b, $reporterB)
            ->get(route('consolidated-reports.index', [
                'report' => 'student_strength',
                'academic_year_id' => $worldB['year']->id,
                'program_id' => $worldB['program']->id,
                'section_id' => $worldB['section']->id,
            ]))
            ->assertOk()
            ->assertViewHas('studentsCount', 2)
            ->assertViewHas('enrollmentsCount', 2)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        $this->asCollege($b, $reporterB)
            ->get(route('consolidated-reports.index', ['report' => 'library']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['books']['total'] === 1
                && $summary['copies']['total'] === 1
                && $summary['members']['total'] === 1
                && $summary['circulation']['issued'] === 1);

        $this->asCollege($b, $reporterB)
            ->get(route('consolidated-reports.index', ['report' => 'certificate']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['total_requests'] === 1
                && $summary['pending_requests'] === 1);

        // The same screen for college A still shows nothing after B was read.
        $this->reporter($a);
        $this->get(route('consolidated-reports.index', ['report' => 'library']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['books']['total'] === 0);
    }

    /**
     * Every consolidated report is empty for the acting college when only
     * another college holds data.
     *
     * @param  array<string, mixed>  $query
     */
    private function assertScopedToNothing(string $report, array $query): void
    {
        $page = $this->get(route('consolidated-reports.index', array_merge(['report' => $report], $query)))->assertOk();

        match ($report) {
            'student_strength' => $page
                ->assertViewHas('studentsCount', 0)
                ->assertViewHas('enrollmentsCount', 0)
                ->assertViewHas('rows', fn ($rows) => $rows->total() === 0),
            'academic' => $page
                ->assertViewHas('sections', fn (array $sections) => $sections['sectionsCount'] === 0 && $sections['studentsCount'] === 0)
                ->assertViewHas('attendance', fn (array $attendance) => array_sum($attendance['counts']) === 0),
            'examination' => $page
                ->assertViewHas('results', fn (array $results) => $results['totalResults'] === 0)
                ->assertViewHas('examinations', fn (array $examinations) => $examinations['rows']->total() === 0),
            'finance' => $page
                ->assertViewHas('summary', fn (array $summary) => $summary['student_fees']['assignments'] === 0
                    && $summary['collections']['payments'] === 0
                    && $summary['net_collected'] === 0.0),
            'hr' => $page->assertViewHas('summary', fn (array $summary) => $summary['staff']['total'] === 0),
            'library' => $page->assertViewHas('summary', fn (array $summary) => $summary['books']['total'] === 0
                && $summary['copies']['total'] === 0
                && $summary['members']['total'] === 0),
            'transport' => $page->assertViewHas('summary', fn (array $summary) => $summary['vehicles']['total'] === 0
                && $summary['routes']['total'] === 0),
            'hostel' => $page->assertViewHas('summary', fn (array $summary) => $summary['total_hostels'] === 0
                && $summary['total_beds'] === 0),
            'inventory' => $page->assertViewHas('summary', fn (array $summary) => $summary['metrics']['total_items'] === 0),
            'communication' => $page->assertViewHas('summary', fn (array $summary) => $summary['notices']['total'] === 0
                && $summary['total_communications'] === 0),
            'certificate' => $page->assertViewHas('summary', fn (array $summary) => $summary['total_requests'] === 0),
            'dashboard', 'management' => $page->assertViewHas('summary', fn (array $summary) => $this->headlineMetric($page, 'students', 'Students') === 0
                && $this->headlineMetric($page, 'library', 'Titles') === 0
                && $this->headlineMetric($page, 'certificates', 'Requests') === 0),
            default => $page,
        };
    }

    /**
     * The value of one Management / MIS indicator.
     *
     * @param  \Illuminate\Testing\TestResponse  $page
     */
    private function indicatorValue($page, string $label): mixed
    {
        foreach ($page->viewData('summary')['indicators'] as $indicator) {
            if ($indicator['label'] === $label) {
                return $indicator['value'];
            }
        }

        return null;
    }

    /**
     * The value of one Management / MIS attention row.
     *
     * @param  \Illuminate\Testing\TestResponse  $page
     */
    private function attentionValue($page, string $module, string $label): mixed
    {
        foreach ($page->viewData('summary')['attention'] as $row) {
            if ($row['module'] === $module && $row['label'] === $label) {
                return $row['value'];
            }
        }

        return null;
    }
}
