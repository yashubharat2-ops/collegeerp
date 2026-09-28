<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Services\FinanceReportService;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\FeeCategory;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructure;
use App\Models\FinanceReport;
use App\Models\Hostel;
use App\Models\HostelFeeAssignment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Finance Reports — a READ-ONLY reporting layer over the existing Finance /
 * Fees module (Fee Structures, Fee Categories, Fee Assignments, Fee Collection,
 * Receipts, Due / Outstanding, Discounts / Concessions, Refunds) and the
 * Transport and Hostel fee charges recorded through it.
 *
 * Nothing is persisted here: no report tables, no calculation, no alternate fee
 * arithmetic — every figure is read live from the existing models and services
 * through FinanceReportService, always tenant-scoped by CollegeScope.
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove a financial record.
 */
class FinanceReportController extends Controller
{
    /** The reports the screen can render, in navigation order. */
    public const REPORTS = [
        'collection' => 'Fee Collection Report',
        'due' => 'Fee Due / Outstanding Report',
        'ledger' => 'Student Fee Ledger',
        'structure' => 'Fee Structure Report',
        'assignment' => 'Fee Assignment Report',
        'concession' => 'Discount / Concession Report',
        'refund' => 'Refund Report',
        'receipt' => 'Receipt Report',
        'transport' => 'Transport Fee Report',
        'hostel' => 'Hostel Fee Report',
        'summary' => 'Financial Summary',
    ];

    /** Filters that apply to each report; any other query parameter is ignored. */
    public const FILTERS = [
        'collection' => ['search', 'academic_year_id', 'program_id', 'section_id', 'student_id', 'fee_type', 'fee_structure_id', 'payment_mode', 'from', 'to'],
        'due' => ['academic_year_id', 'program_id', 'student_id', 'fee_structure_id', 'status'],
        'ledger' => ['search', 'academic_year_id', 'program_id', 'section_id', 'student_id', 'status'],
        'structure' => ['search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'fee_category_id', 'status'],
        'assignment' => ['search', 'academic_year_id', 'program_id', 'section_id', 'student_id', 'fee_structure_id', 'status', 'from', 'to'],
        'concession' => ['search', 'academic_year_id', 'program_id', 'section_id', 'student_id', 'fee_structure_id', 'type', 'status', 'from', 'to'],
        'refund' => ['search', 'academic_year_id', 'program_id', 'student_id', 'status', 'from', 'to'],
        'receipt' => ['search', 'academic_year_id', 'program_id', 'section_id', 'student_id', 'fee_type', 'fee_structure_id', 'payment_mode', 'from', 'to'],
        'transport' => ['search', 'academic_year_id', 'program_id', 'student_id', 'route_id', 'stop_id', 'status', 'from', 'to'],
        'hostel' => ['search', 'academic_year_id', 'academic_term_id', 'program_id', 'student_id', 'hostel_id', 'status', 'from', 'to'],
        'summary' => ['academic_year_id', 'program_id'],
    ];

    /** Date-range meaning per report (for labels in the filter form). */
    public const DATE_LABELS = [
        'collection' => 'Payment date',
        'assignment' => 'Assigned date',
        'concession' => 'Recorded date',
        'refund' => 'Refund date',
        'receipt' => 'Payment date',
        'transport' => 'Effective date',
        'hostel' => 'Effective date',
    ];

    private const KEYS = [
        'search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id',
        'student_id', 'fee_structure_id', 'fee_category_id', 'type', 'status', 'fee_type',
        'payment_mode', 'route_id', 'stop_id', 'hostel_id', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id',
        'student_id', 'fee_structure_id', 'fee_category_id', 'route_id', 'stop_id', 'hostel_id',
    ];

    private const STRING_KEYS = ['type', 'status', 'fee_type', 'payment_mode'];

    public function index(Request $request, FinanceReportService $reports): View
    {
        $this->authorize('viewAny', FinanceReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'collection';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'due' => $reports->dueOutstanding($filters),
            'ledger' => $reports->studentLedger($filters),
            'structure' => $reports->feeStructure($filters),
            'assignment' => $reports->assignment($filters),
            'concession' => $reports->concession($filters),
            'refund' => $reports->refund($filters),
            'receipt' => $reports->receipt($filters),
            'transport' => $reports->transport($filters),
            'hostel' => $reports->hostel($filters),
            'summary' => $reports->summary($filters),
            default => $reports->collection($filters),
        };

        return view('finance_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusKey' => self::statuses($report) === [] ? null : 'status',
            'statusLabel' => self::statusLabel($report),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
            'searchLabel' => self::searchLabel($report),
            'searchPlaceholder' => self::searchPlaceholder($report),
        ]));
    }

    /**
     * The selectable statuses of the selected report (empty = no status filter).
     *
     * Each report validates its own vocabulary: the Due / Ledger reports use the
     * derived ledger statuses (paid / partial / due), the others the operational
     * status of the record they list.
     */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'due', 'ledger' => FeeLedger::STATUSES,
            'structure' => FeeStructure::STATUSES,
            'assignment' => StudentFeeAssignment::STATUSES,
            'concession' => FeeConcession::STATUSES,
            'refund' => FeeRefund::STATUSES,
            'transport' => StudentTransportFeeAssignment::STATUSES,
            'hostel' => HostelFeeAssignment::STATUSES,
            default => [],
        };
    }

    private static function statusLabel(string $report): string
    {
        return match ($report) {
            'due', 'ledger' => 'Ledger status',
            'structure' => 'Structure status',
            'assignment' => 'Assignment status',
            'concession' => 'Concession status',
            'refund' => 'Refund status',
            'transport' => 'Fee status',
            'hostel' => 'Fee status',
            default => 'Status',
        };
    }

    private static function searchLabel(string $report): string
    {
        return match ($report) {
            'structure' => 'Search fee structure',
            'concession' => 'Search concession',
            'refund' => 'Search refund',
            'receipt' => 'Search receipt',
            default => 'Search student',
        };
    }

    private static function searchPlaceholder(string $report): string
    {
        return match ($report) {
            'structure' => 'Structure name or code',
            'concession' => 'Reason, student number or name',
            'refund' => 'Refund / receipt number or student',
            'receipt' => 'Receipt / reference number or student',
            default => 'Student number or name',
        };
    }

    private function filters(Request $request, string $report): array
    {
        $statuses = self::statuses($report);
        $visible = self::FILTERS[$report];
        $uses = fn (string $key): bool => in_array($key, $visible, true);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'academic_term_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'fee_structure_id' => ['nullable', 'integer', 'min:1'],
            'fee_category_id' => ['nullable', 'integer', 'min:1'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'stop_id' => ['nullable', 'integer', 'min:1'],
            'hostel_id' => ['nullable', 'integer', 'min:1'],
            // A vocabulary is enforced only where the selected report actually
            // uses the filter; elsewhere the parameter is ignored, not judged.
            'type' => ['nullable', $uses('type') ? Rule::in(FeeConcession::TYPES) : 'string'],
            'status' => ['nullable', $statuses === [] ? 'string' : Rule::in($statuses)],
            'fee_type' => ['nullable', $uses('fee_type') ? Rule::in(FinanceReportService::FEE_TYPES) : 'string'],
            'payment_mode' => ['nullable', $uses('payment_mode') ? Rule::in(FeePayment::MODES) : 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Only the filters of the selected report take effect; the service always
        // receives the same keys. An integer ID from another college matches
        // nothing, because every root query is tenant-scoped.
        $filters = array_fill_keys(self::KEYS, null);
        foreach (self::FILTERS[$report] as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }
        $filters['search'] = trim((string) ($filters['search'] ?? ''));

        foreach (self::INTEGER_KEYS as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (self::STRING_KEYS as $key) {
            $filters[$key] = filled($filters[$key]) ? trim((string) $filters[$key]) : null;
        }

        return $filters;
    }

    /** Tenant-scoped dropdown options, loaded only for the filters shown. */
    private function options(string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);
        $options = [];

        if (isset($visible['academic_year_id'])) {
            $options['academicYears'] = AcademicYear::query()->orderByDesc('starts_on')->orderByDesc('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['academic_term_id'])) {
            $options['academicTerms'] = AcademicTerm::query()->with('academicYear:id,name')
                ->orderBy('academic_year_id')->orderBy('sequence')->orderBy('id')->get(['id', 'name', 'code', 'academic_year_id']);
        }
        if (isset($visible['department_id'])) {
            $options['departments'] = Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);
        }
        if (isset($visible['program_id'])) {
            $options['programs'] = Program::query()->with('department:id,name')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'department_id']);
        }
        if (isset($visible['section_id'])) {
            $options['sections'] = Section::query()->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
        }
        if (isset($visible['student_id'])) {
            $options['students'] = Student::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name']);
        }
        if (isset($visible['fee_structure_id'])) {
            $options['feeStructures'] = FeeStructure::query()->with('academicYear:id,name')
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id', 'status']);
        }
        if (isset($visible['fee_category_id'])) {
            $options['feeCategories'] = FeeCategory::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['route_id'])) {
            $options['routes'] = TransportRoute::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['stop_id'])) {
            $options['stops'] = TransportStop::query()->orderBy('route_id')->orderBy('sequence')->orderBy('id')
                ->get(['id', 'route_id', 'name', 'code']);
        }
        if (isset($visible['hostel_id'])) {
            $options['hostels'] = Hostel::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }

        // The smaller vocabularies the forms select from.
        $options['types'] = FeeConcession::TYPES;
        $options['feeTypes'] = FinanceReportService::FEE_TYPES;
        $options['paymentModes'] = FeePayment::MODES;

        return $options;
    }
}
