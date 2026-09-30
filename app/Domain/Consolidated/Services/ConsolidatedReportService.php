<?php

namespace App\Domain\Consolidated\Services;

use App\Domain\Academic\Services\AcademicReportService;
use App\Domain\Communication\Services\CommunicationReportService;
use App\Domain\Finance\Services\FinanceReportService;
use App\Domain\HR\Services\HrReportService;
use App\Domain\Hostel\Services\HostelReportService;
use App\Domain\Inventory\Services\InventoryReportService;
use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Library\Services\LibraryReportService;
use App\Domain\Student\Services\StudentReportService;
use App\Domain\Transport\Services\TransportReportService;
use App\Services\Certificates\CertificateReportService;
use App\Services\Examinations\ExaminationReportService;
use Carbon\CarbonImmutable;

/**
 * ConsolidatedReportService — the read side of REPORTS → Consolidated Reports.
 *
 * Thirteen cross-module summaries for the college that is currently selected:
 *
 *   1.  College Dashboard Summary   → one headline group per module
 *   2.  Student Strength Summary    → StudentReportService::strength()
 *   3.  Academic Summary            → AcademicReportService (section strength + attendance)
 *   4.  Examination Summary         → ExaminationReportService (summary + pass / fail)
 *   5.  Fee / Finance Summary       → FinanceReportService::summary()
 *   6.  HR Summary                  → HrReportService::summary()
 *   7.  Library Summary             → LibraryReportService::summary()
 *   8.  Transport Summary           → TransportReportService::summary()
 *   9.  Hostel Summary              → HostelReportService::summary()
 *   10. Inventory / Asset Summary   → InventoryReportService::summary()
 *   11. Communication Summary       → CommunicationReportService::summary()
 *   12. Certificate Summary         → CertificateReportService::summary()
 *   13. Management / MIS Reports    → the module summaries above, combined
 *
 * There is NO reporting table, NO snapshot and NO second copy of any fact. Every
 * row and every figure is produced by the service that already owns it; this
 * class only translates filters, delegates and (for the dashboard / management
 * view) places the results side by side. Nothing here writes, and no module rule
 * is re-implemented: vocabularies, balances, fees, attendance, stock and result
 * definitions are the ones the operational and report services store.
 *
 * The Management / MIS ratios are arithmetic over two figures that are
 * themselves read live (for example net collected ÷ total fee value); they add
 * no column, no table and no stored value.
 *
 * Tenant safety: every delegated service queries CollegeScope models, so the
 * figures always describe the currently selected college and an id belonging to
 * another college can only ever narrow a report to nothing.
 */
class ConsolidatedReportService
{
    public function __construct(
        private readonly StudentReportService $students,
        private readonly AcademicReportService $academics,
        private readonly ExaminationReportService $examinations,
        private readonly FinanceReportService $finance,
        private readonly HrReportService $hr,
        private readonly LibraryReportService $library,
        private readonly TransportReportService $transport,
        private readonly HostelReportService $hostel,
        private readonly InventoryReportService $inventory,
        private readonly CommunicationReportService $communication,
        private readonly CertificateReportService $certificates,
    ) {}

    /* ------------------------------------------------------------------ *\
     * 1. College Dashboard Summary
     * \* ------------------------------------------------------------------ */

    /**
     * High-level operational counts, one card group per existing module. Every
     * figure is a headline of that module's own report service, so the dashboard
     * cannot disagree with the module screens.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array{groups: list<array<string, mixed>>}}
     */
    public function dashboard(array $filters): array
    {
        return ['summary' => ['groups' => $this->headlineGroups($this->moduleSummaries($filters))]];
    }

    /* ------------------------------------------------------------------ *\
     * 2. Student Strength Summary
     * \* ------------------------------------------------------------------ */

    /**
     * The existing Student Strength derivation (distinct students per academic
     * year / program / section, plus the two totals) — this method introduces no
     * second definition of "strength".
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: \Illuminate\Contracts\Pagination\LengthAwarePaginator, studentsCount: int, enrollmentsCount: int}
     */
    public function studentStrength(array $filters): array
    {
        return $this->students->strength($this->studentFilters($filters));
    }

    /* ------------------------------------------------------------------ *\
     * 3. Academic Summary
     * \* ------------------------------------------------------------------ */

    /**
     * The existing Class / Section Strength report (sections, capacity and live
     * active-student counts) plus the existing attendance register counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function academic(array $filters): array
    {
        $filters = $this->academicFilters($filters);

        return [
            'sections' => $this->academics->sectionStrength($filters),
            'attendance' => $this->academics->attendance($filters),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 4. Examination Summary
     * \* ------------------------------------------------------------------ */

    /**
     * The existing Examination Summary (per-examination schedule, subject,
     * attendance, marks, result and publication counts) plus the existing
     * pass / fail aggregate over published results.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function examination(array $filters): array
    {
        $filters = $this->examinationFilters($filters);

        return [
            'examinations' => $this->examinations->summary($filters),
            'results' => $this->examinations->passFail($filters),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 5–12. Module summaries
     * \* ------------------------------------------------------------------ */

    /**
     * Fee / Finance Summary — the existing Financial Summary of the Finance
     * Reports module (student / transport / hostel fee ledgers, collections,
     * concessions, refunds and the ledger reconciliation).
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function finance(array $filters): array
    {
        return $this->finance->summary($this->financeFilters($filters));
    }

    /**
     * HR Summary — the existing HR Summary (staff, master data, documents,
     * attendance, leave, payroll and the payroll reconciliation).
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function hr(array $filters): array
    {
        return $this->hr->summary($this->hrFilters($filters));
    }

    /**
     * Library Summary — the existing Library Summary (books, copies, members,
     * circulation and fines).
     *
     * @return array{summary: array<string, mixed>}
     */
    public function library(): array
    {
        return ['summary' => $this->library->summary()];
    }

    /**
     * Transport Summary — the existing Transport Summary (fleet, drivers,
     * routes / stops, assignments and transport fees).
     *
     * @return array{summary: array<string, mixed>}
     */
    public function transport(): array
    {
        return ['summary' => $this->transport->summary()];
    }

    /**
     * Hostel Summary — the existing Hostel Summary (hostels, buildings, rooms,
     * beds, allocations, attendance and hostel fees). The Hostel service already
     * returns the `summary` envelope.
     *
     * @return array{summary: array<string, mixed>}
     */
    public function hostel(): array
    {
        return $this->hostel->summary();
    }

    /**
     * Inventory / Asset Summary — the existing Inventory Summary, including its
     * ledger-derived stock grouped by unit (quantities of unlike units are never
     * added together).
     *
     * @return array{summary: array<string, mixed>}
     */
    public function inventory(string $threshold): array
    {
        return ['summary' => $this->inventory->summary($threshold)];
    }

    /**
     * Communication Summary — the existing Communication Summary (notices,
     * circulars, internal notifications, templates and the SMS / e-mail logs).
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function communication(array $filters): array
    {
        return ['summary' => $this->communication->summary($this->communicationFilters($filters))];
    }

    /**
     * Certificate Summary — the existing Certificate Summary (requests,
     * generation, issuance, verification and the type-wise breakdown).
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function certificate(array $filters): array
    {
        return ['summary' => $this->certificates->summary($this->certificateFilters($filters))];
    }

    /* ------------------------------------------------------------------ *\
     * 13. Management / MIS Reports
     * \* ------------------------------------------------------------------ */

    /**
     * A management-level view built from the same module summaries the other
     * tabs show:
     *
     *   - `groups`     the headline figures of every module (College Dashboard Summary);
     *   - `indicators` ratios derived from two existing figures (formula shown in the UI);
     *   - `attention`  the open / pending / overdue figures the modules already publish.
     *
     * No field, table or stored value is added and nothing is estimated.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function management(array $filters): array
    {
        $data = $this->moduleSummaries($filters);

        return ['summary' => [
            'groups' => $this->headlineGroups($data),
            'indicators' => $this->managementIndicators($data),
            'attention' => $this->attentionItems($data),
        ]];
    }

    /* ------------------------------------------------------------------ *\
     * Module summaries (each figure comes from the module that owns it)
     * \* ------------------------------------------------------------------ */

    /**
     * One live summary per existing module, read through that module's own
     * report service. The consolidated filters are translated into the
     * vocabulary each service already expects; anything a module cannot use is
     * left out rather than guessed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function moduleSummaries(array $filters): array
    {
        $examinations = $this->examinations->summary($this->examinationFilters($filters));

        return [
            'students' => $this->students->strength($this->studentFilters($filters)),
            'academics' => $this->academics->sectionStrength($this->academicFilters($filters)),
            'examinations' => $examinations,
            'results' => $this->examinations->passFail($this->examinationFilters($filters)),
            'finance' => $this->finance->summary($this->financeFilters($filters))['summary'],
            'hr' => $this->hr->summary($this->hrFilters($filters))['summary'],
            'library' => $this->library->summary(),
            'transport' => $this->transport->summary(),
            'hostel' => $this->hostel->summary()['summary'],
            'inventory' => $this->inventory->summary($this->lowStockThreshold($filters)),
            'communication' => $this->communication->summary($this->communicationFilters($filters)),
            'certificates' => $this->certificates->summary($this->certificateFilters($filters)),
        ];
    }

    /**
     * The normalized headline cards shared by the College Dashboard Summary and
     * the Management / MIS report: one group per module, each figure taken from
     * that module's own report service.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function headlineGroups(array $data): array
    {
        $students = $data['students'];
        $academics = $data['academics'];
        $examinations = $data['examinations'];
        $results = $data['results'];
        $finance = $data['finance'];
        $hr = $data['hr'];
        $library = $data['library'];
        $transport = $data['transport'];
        $hostel = $data['hostel'];
        $inventory = $data['inventory']['metrics'];
        $communication = $data['communication'];
        $certificates = $data['certificates'];

        return [
            $this->group('students', 'Students', 'Student Reports', [
                $this->metric('Students', $students['studentsCount']),
                $this->metric('Enrollments', $students['enrollmentsCount']),
            ]),
            $this->group('academics', 'Academics', 'Academic Reports', [
                $this->metric('Sections / batches', $academics['sectionsCount']),
                $this->metric('Active students in sections', $academics['studentsCount']),
                $this->metric('Section capacity', $academics['capacityTotal']),
            ]),
            $this->group('examinations', 'Examinations', 'Examination Reports', [
                $this->metric('Examinations', $examinations['rows']->total()),
                $this->metric('Published results', $results['totalResults']),
                $this->metric('Passed', $results['passCount']),
                $this->metric('Pass rate', $results['passRate'], false, '%'),
            ]),
            $this->group('finance', 'Fee / Finance', 'Finance Reports', [
                $this->metric('Total fee value', $finance['fee_value_total'], true),
                $this->metric('Net collected', $finance['net_collected'], true),
                $this->metric('Outstanding', $finance['outstanding_total'], true),
                $this->metric('Collections', $finance['collections']['payments']),
            ]),
            $this->group('hr', 'HR / Staff', 'HR Reports', [
                $this->metric('Staff records', $hr['staff']['total']),
                $this->metric('Active staff', $hr['staff']['active']),
                $this->metric('Payroll records', $hr['payroll']['payrolls']),
                $this->metric('Net payroll', $hr['payroll']['net'], true),
            ]),
            $this->group('library', 'Library', 'Library Reports', [
                $this->metric('Titles', $library['books']['total']),
                $this->metric('Copies', $library['copies']['total']),
                $this->metric('Members', $library['members']['total']),
                $this->metric('On loan', $library['circulation']['issued']),
                $this->metric('Overdue', $library['circulation']['overdue']),
            ]),
            $this->group('transport', 'Transport', 'Transport Reports', [
                $this->metric('Vehicles', $transport['vehicles']['total']),
                $this->metric('Active vehicles', $transport['vehicles']['active']),
                $this->metric('Routes', $transport['routes']['total']),
                $this->metric('Stops', $transport['stops']['total']),
                $this->metric('Active assignments', $transport['assignments']['active']),
            ]),
            $this->group('hostel', 'Hostel', 'Hostel Reports', [
                $this->metric('Hostels', $hostel['total_hostels']),
                $this->metric('Beds', $hostel['total_beds']),
                $this->metric('Occupied beds', $hostel['occupied_beds']),
                $this->metric('Available beds', $hostel['available_beds']),
                $this->metric('Active allocations', $hostel['active_allocations']),
            ]),
            $this->group('inventory', 'Inventory / Assets', 'Inventory / Asset Reports', [
                $this->metric('Items / assets', $inventory['total_items']),
                $this->metric('Assets', $inventory['total_assets']),
                $this->metric('Low-stock items', $inventory['low_stock_items']),
                $this->metric('Stored purchase value', $inventory['purchase_order_value'], true),
                $this->metric('Assets assigned', $inventory['assets_assigned']),
                $this->metric('Under maintenance', $inventory['assets_under_maintenance']),
            ]),
            $this->group('communication', 'Communication', 'Communication Reports', [
                $this->metric('Notices', $communication['notices']['total']),
                $this->metric('Circulars', $communication['circulars']['total']),
                $this->metric('Notifications', $communication['notifications']['total']),
                $this->metric('SMS / e-mail logs', $communication['total_communications']),
                $this->metric('Failed communications', $communication['failed_communications']),
            ]),
            $this->group('certificates', 'Certificates', 'Certificate Reports', [
                $this->metric('Requests', $certificates['total_requests']),
                $this->metric('Pending requests', $certificates['pending_requests']),
                $this->metric('Issued', $certificates['total_issued']),
                $this->metric('Verified', $certificates['total_verified']),
            ]),
        ];
    }

    /**
     * Management indicators: each ratio is arithmetic over two figures the
     * module summaries already expose. A null value means "not applicable"
     * (there is nothing to divide by) and the view renders "—".
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: float|null, suffix: string, formula: string}>
     */
    private function managementIndicators(array $data): array
    {
        $finance = $data['finance'];
        $hr = $data['hr'];
        $library = $data['library'];
        $hostel = $data['hostel'];
        $students = $data['students'];
        $results = $data['results'];

        return [
            $this->indicator(
                'Fee collection rate',
                'Net collected ÷ total fee value (student + transport + hostel charges).',
                $this->percentage($finance['net_collected'], $finance['fee_value_total']),
                '%',
            ),
            $this->indicator(
                'Outstanding fee share',
                'Outstanding ÷ total fee value.',
                $this->percentage($finance['outstanding_total'], $finance['fee_value_total']),
                '%',
            ),
            $this->indicator(
                'Hostel bed occupancy',
                'Occupied beds ÷ beds (the Hostel dashboard counters).',
                $this->percentage($hostel['occupied_beds'], $hostel['total_beds']),
                '%',
            ),
            $this->indicator(
                'Library copy availability',
                'Available copies ÷ copies.',
                $this->percentage($library['copies']['available'], $library['copies']['total']),
                '%',
            ),
            $this->indicator(
                'Result pass rate',
                'Passed published results ÷ published results (the Examination Report definition).',
                $results['passRate'] === null ? null : round((float) $results['passRate'], 1),
                '%',
            ),
            $this->indicator(
                'Staff attendance rate',
                'Present ÷ staff attendance entries (the HR Report definition).',
                $this->rate($hr['attendance']['rate']),
                '%',
            ),
            $this->indicator(
                'Students per active staff member',
                'Students ÷ active staff.',
                $this->ratio($students['studentsCount'], $hr['staff']['active']),
            ),
        ];
    }

    /**
     * The open / pending / overdue figures the owning modules already publish.
     * A row needs attention only while its value is greater than zero.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{module: string, label: string, value: int|float, money: bool, attention: bool}>
     */
    private function attentionItems(array $data): array
    {
        $finance = $data['finance'];
        $hr = $data['hr'];
        $library = $data['library'];
        $transport = $data['transport'];
        $inventory = $data['inventory']['metrics'];
        $communication = $data['communication'];
        $certificates = $data['certificates'];

        return [
            $this->attention('Finance', 'Outstanding fee balance', (float) $finance['outstanding_total'], true),
            $this->attention('Inventory', 'Items at or below the low-stock threshold', $inventory['low_stock_items']),
            $this->attention('Inventory', 'Assets under maintenance', $inventory['assets_under_maintenance']),
            $this->attention('Library', 'Overdue library issues', $library['circulation']['overdue']),
            $this->attention('Library', 'Outstanding library fines', (float) $library['fines']['outstanding'], true),
            $this->attention('Transport', 'Vehicles in maintenance', $transport['vehicles']['maintenance']),
            $this->attention('HR', 'Employee documents expiring within 30 days', $hr['documents']['expiring']),
            $this->attention('HR', 'Employee documents already expired', $hr['documents']['expired']),
            $this->attention('HR', 'Leave requests pending approval', $hr['leave']['pending']),
            $this->attention('Certificates', 'Certificate requests pending', $certificates['pending_requests']),
            $this->attention('Certificates', 'Issued certificates not yet verified', $certificates['unverified_issued']),
            $this->attention('Communication', 'Failed communications (SMS / e-mail)', $communication['failed_communications']),
        ];
    }

    /* ------------------------------------------------------------------ *\
     * Filter translation (the controller normalises the query string)
     * \* ------------------------------------------------------------------ */

    /**
     * The StudentReportService vocabulary. Student and enrollment statuses fall
     * back to `active`, exactly like the Student Strength Report; the controller
     * passes `all` when the user asks for every status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function studentFilters(array $filters): array
    {
        return [
            'search' => '',
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'program_id' => $filters['program_id'] ?? null,
            'department_id' => null,
            'section_id' => $filters['section_id'] ?? null,
            'student_status' => $filters['student_status'] ?? 'active',
            'enrollment_status' => $filters['enrollment_status'] ?? 'active',
            'admission_status' => null,
            'promotion_status' => null,
            'transfer_status' => null,
            'tc_status' => null,
            'document_status' => null,
            'entry_type' => null,
            'gender' => null,
            'from' => null,
            'to' => null,
        ];
    }

    /**
     * The AcademicReportService vocabulary.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function academicFilters(array $filters): array
    {
        return [
            'search' => '',
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'academic_term_id' => $filters['academic_term_id'] ?? null,
            'department_id' => null,
            'program_id' => $filters['program_id'] ?? null,
            'section_id' => $filters['section_id'] ?? null,
            'subject_id' => null,
            'faculty_id' => null,
            'status' => null,
            'attendance_status' => null,
            'day_of_week' => null,
            'event_type' => null,
            'group' => null,
            'below' => null,
            'from' => null,
            'to' => null,
        ];
    }

    /**
     * The ExaminationReportService vocabulary (`search` must be a string).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function examinationFilters(array $filters): array
    {
        return [
            'search' => '',
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'academic_term_id' => $filters['academic_term_id'] ?? null,
            'examination_id' => $filters['examination_id'] ?? null,
            'department_id' => null,
            'program_id' => $filters['program_id'] ?? null,
            'section_id' => null,
            'subject_id' => null,
            'faculty_id' => null,
            'status' => null,
            'attendance_status' => null,
            'mark_status' => null,
            'result_status' => null,
            'grade' => null,
            'from' => null,
            'to' => null,
        ];
    }

    /**
     * The FinanceReportService summary scope (academic year + program).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function financeFilters(array $filters): array
    {
        return [
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'program_id' => $filters['program_id'] ?? null,
        ];
    }

    /**
     * The HrReportService vocabulary.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function hrFilters(array $filters): array
    {
        return [
            'department_id' => $filters['department_id'] ?? null,
            'designation_id' => $filters['designation_id'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ];
    }

    /**
     * The CommunicationReportService summary window.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function communicationFilters(array $filters): array
    {
        return [
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ];
    }

    /**
     * The CertificateReportService summary filters: the certificate type and the
     * created-at window, parsed into the Carbon instances the service expects.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function certificateFilters(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return [
            'certificate_type_id' => isset($filters['certificate_type_id']) ? (int) $filters['certificate_type_id'] : null,
            'date_from' => $from ? CarbonImmutable::parse((string) $from) : null,
            'date_to' => $to ? CarbonImmutable::parse((string) $to) : null,
            'search' => null,
        ];
    }

    /**
     * The inventory low-stock threshold of the current request, falling back to
     * the threshold the Inventory module already uses.
     *
     * @param  array<string, mixed>  $filters
     */
    private function lowStockThreshold(array $filters): string
    {
        $threshold = $filters['threshold'] ?? null;

        return $threshold === null || $threshold === ''
            ? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD
            : (string) $threshold;
    }

    /* ------------------------------------------------------------------ *\
     * Small builders and arithmetic helpers
     * \* ------------------------------------------------------------------ */

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array{key: string, title: string, source: string, metrics: list<array<string, mixed>>}
     */
    private function group(string $key, string $title, string $source, array $metrics): array
    {
        return ['key' => $key, 'title' => $title, 'source' => $source, 'metrics' => $metrics];
    }

    /**
     * Counts stay whole numbers; money keeps two decimals and a rate keeps one.
     *
     * @return array{label: string, value: int|float|null, money: bool, suffix: string|null}
     */
    private function metric(string $label, int|float|string|null $value, bool $money = false, ?string $suffix = null): array
    {
        $number = match (true) {
            $value === null => null,
            $money => round((float) $value, 2),
            $suffix === '%' => round((float) $value, 1),
            default => (int) $value,
        };

        return ['label' => $label, 'value' => $number, 'money' => $money, 'suffix' => $suffix];
    }

    /**
     * @return array{label: string, value: float|null, suffix: string, formula: string}
     */
    private function indicator(string $label, string $formula, ?float $value, string $suffix = ''): array
    {
        return ['label' => $label, 'value' => $value, 'suffix' => $suffix, 'formula' => $formula];
    }

    /**
     * @return array{module: string, label: string, value: int|float, money: bool, attention: bool}
     */
    private function attention(string $module, string $label, int|float|string $value, bool $money = false): array
    {
        $number = $money ? round((float) $value, 2) : (int) $value;

        return [
            'module' => $module,
            'label' => $label,
            'value' => $number,
            'money' => $money,
            'attention' => $number > 0,
        ];
    }

    /** A percentage rounded to one decimal, or null when there is no base. */
    private function percentage(int|float|string|null $part, int|float|string|null $whole): ?float
    {
        $whole = (float) $whole;

        return $whole > 0.0 ? round((float) $part * 100 / $whole, 1) : null;
    }

    /** A ratio rounded to two decimals, or null when there is no base. */
    private function ratio(int|float|string|null $part, int|float|string|null $whole): ?float
    {
        $whole = (float) $whole;

        return $whole > 0.0 ? round((float) $part / $whole, 2) : null;
    }

    /** The HR attendance rate, which the HR service reports as 0.0 when empty. */
    private function rate(int|float|string|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
