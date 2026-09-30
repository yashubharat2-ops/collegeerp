<?php

namespace App\Http\Controllers;

use App\Domain\Consolidated\Services\ConsolidatedReportService;
use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\CertificateType;
use App\Models\ConsolidatedReport;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Examination;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consolidated Reports — a READ-ONLY, tenant-scoped reporting layer that
 * presents the college-wide summaries of the existing modules in one place
 * (REPORTS → Consolidated Reports).
 *
 * Nothing is persisted and nothing is duplicated: no report tables, no
 * snapshots and no second copy of any fact. Every row and every figure is
 * produced by the report service that already owns it — Student, Academic,
 * Examination, Finance, HR, Library, Transport, Hostel, Inventory,
 * Communication and Certificate — always for the currently selected college,
 * because those services query tenant-scoped models only.
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove a record; the module only reads.
 */
class ConsolidatedReportController extends Controller
{
    /** The thirteen reports the screen can render, in navigation order. */
    public const REPORTS = [
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

    /**
     * Filters that apply to each report; any other query parameter is ignored
     * and never enforced. Only the filters relevant to the selected report are
     * offered, so no report carries a filter it cannot use — every filter is
     * passed to the module service that already supports it.
     */
    public const FILTERS = [
        // Dashboard: the academic scope shared by Students / Academics / Examinations / Finance.
        'dashboard' => ['academic_year_id', 'program_id'],
        // Strength: the exact filters of the Student Strength Report.
        'student_strength' => ['academic_year_id', 'program_id', 'section_id', 'student_status', 'enrollment_status'],
        // Academics: the Class / Section Strength + Attendance filters.
        'academic' => ['academic_year_id', 'academic_term_id', 'program_id', 'section_id'],
        // Examinations: the Examination Summary filters.
        'examination' => ['academic_year_id', 'academic_term_id', 'program_id', 'examination_id'],
        // Finance: the Financial Summary scope (academic year + program).
        'finance' => ['academic_year_id', 'program_id'],
        // HR: the HR Summary staff scope, from/to the joining-date window.
        'hr' => ['department_id', 'designation_id', 'from', 'to'],
        // Library / Transport / Hostel summaries have no filter of their own.
        'library' => [],
        'transport' => [],
        'hostel' => [],
        // Inventory: the low-stock threshold the Inventory Summary already uses.
        'inventory' => ['threshold'],
        // Communication: the summary window (notice / circular / notification / log dates).
        'communication' => ['from', 'to'],
        // Certificates: the type and the created-at window of the Certificate Summary.
        'certificate' => ['certificate_type_id', 'from', 'to'],
        // Management / MIS: the same academic scope as the dashboard.
        'management' => ['academic_year_id', 'program_id'],
    ];

    /** Date-range meaning per report (for the labels in the filter form). */
    public const DATE_LABELS = [
        'hr' => 'Joining date',
        'communication' => 'Communication date',
        'certificate' => 'Request date',
    ];

    private const KEYS = [
        'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'designation_id',
        'program_id', 'section_id', 'certificate_type_id', 'student_status', 'enrollment_status',
        'threshold', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'designation_id',
        'program_id', 'section_id', 'certificate_type_id',
    ];

    private const STRING_KEYS = ['student_status', 'enrollment_status'];

    public function index(Request $request, ConsolidatedReportService $reports): View
    {
        $this->authorize('viewAny', ConsolidatedReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'dashboard';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'student_strength' => $reports->studentStrength($filters),
            'academic' => $reports->academic($filters),
            'examination' => $reports->examination($filters),
            'finance' => $reports->finance($filters),
            'hr' => $reports->hr($filters),
            'library' => $reports->library(),
            'transport' => $reports->transport(),
            'hostel' => $reports->hostel(),
            'inventory' => $reports->inventory($filters['threshold'] ?? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD),
            'communication' => $reports->communication($filters),
            'certificate' => $reports->certificate($filters),
            'management' => $reports->management($filters),
            default => $reports->dashboard($filters),
        };

        // Options first: the report payload is what the partials read, so a
        // dropdown list can never shadow a key the report owns.
        return view('consolidated_reports.index', array_merge($this->options($report), $data, [
            'college' => app(TenantContext::class)->college(),
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'studentStatuses' => [...Student::STATUSES, 'all'],
            'enrollmentStatuses' => [...StudentEnrollment::STATUSES, 'all'],
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
        ]));
    }

    /**
     * Validate and normalise the query string into the vocabulary the module
     * services already expect. Only the filters of the selected report take
     * effect; an id from another college is a perfectly valid integer here and
     * simply matches nothing, because every module query is tenant-scoped.
     */
    private function filters(Request $request, string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);

        // Only the filters the selected report actually offers are validated —
        // like the module report screens, a parameter a report cannot use is
        // ignored rather than judged.
        $rules = [];
        foreach (self::INTEGER_KEYS as $key) {
            if (isset($visible[$key])) {
                $rules[$key] = ['nullable', 'integer', 'min:1'];
            }
        }
        if (isset($visible['student_status'])) {
            $rules['student_status'] = ['nullable', Rule::in([...Student::STATUSES, 'all'])];
        }
        if (isset($visible['enrollment_status'])) {
            $rules['enrollment_status'] = ['nullable', Rule::in([...StudentEnrollment::STATUSES, 'all'])];
        }
        if (isset($visible['threshold'])) {
            $rules['threshold'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99'];
        }
        if (isset($visible['from'])) {
            $rules['from'] = ['nullable', 'date_format:Y-m-d'];
            $rules['to'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'];
        }

        $validated = $request->validate($rules);

        $filters = array_fill_keys(self::KEYS, null);
        foreach (self::FILTERS[$report] as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }

        foreach (self::INTEGER_KEYS as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (self::STRING_KEYS as $key) {
            $filters[$key] = filled($filters[$key]) ? trim((string) $filters[$key]) : null;
        }
        if (isset($visible['threshold'])) {
            $filters['threshold'] = number_format(
                (float) ($filters['threshold'] ?? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD),
                2,
                '.',
                ''
            );
        }

        return $filters;
    }

    /** Tenant-scoped dropdown options, loaded only for the filters shown. */
    private function options(string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);
        $options = [];

        if (isset($visible['academic_year_id'])) {
            $options['academicYears'] = AcademicYear::query()
                ->orderByDesc('starts_on')->orderByDesc('id')
                ->get(['id', 'name', 'code']);
        }
        if (isset($visible['academic_term_id'])) {
            $options['academicTerms'] = AcademicTerm::query()
                ->with('academicYear:id,name')
                ->orderBy('academic_year_id')->orderBy('sequence')->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id']);
        }
        if (isset($visible['program_id'])) {
            $options['programs'] = Program::query()
                ->with('department:id,name')
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'department_id']);
        }
        if (isset($visible['section_id'])) {
            // Named *Options so a dropdown can never shadow a report payload
            // (the Academic Summary publishes its own `sections`).
            $options['sectionOptions'] = Section::query()
                ->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
        }
        if (isset($visible['examination_id'])) {
            // Named *Options so a dropdown can never shadow a report payload
            // (the Examination Summary publishes its own `examinations`).
            $options['examinationOptions'] = Examination::query()
                ->orderByDesc('start_date')->orderByDesc('id')
                ->get(['id', 'name', 'code']);
        }
        if (isset($visible['department_id'])) {
            $options['departments'] = Department::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name']);
        }
        if (isset($visible['designation_id'])) {
            $options['designations'] = Designation::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name']);
        }
        if (isset($visible['certificate_type_id'])) {
            $options['certificateTypes'] = CertificateType::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code']);
        }

        return $options;
    }
}
