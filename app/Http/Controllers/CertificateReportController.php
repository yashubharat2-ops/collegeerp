<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Certificate;
use App\Models\CertificateReport;
use App\Models\CertificateType;
use App\Models\Program;
use App\Models\Student;
use App\Services\Certificates\CertificateReportService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * CertificateReportController — single read-only entry point for the
 * Certificate Reports module (REPORTS → Certificate Reports).
 *
 * Strictly GET-only: renders one of the five Certificate reports selected by
 * the `?report=` query parameter and never mutates any Certificate record.
 */
class CertificateReportController extends Controller
{
    /**
     * Ordered map of report keys to human-readable report titles.
     *
     * @var array<string, string>
     */
    public const REPORTS = [
        'requests' => 'Certificate Request Report',
        'issuance' => 'Certificate Issuance Report',
        'verification' => 'Certificate Verification Report',
        'types' => 'Certificate Type-wise Report',
        'summary' => 'Certificate Summary',
    ];

    /**
     * Which filter controls are active for each report tab.
     *
     * @var array<string, list<string>>
     */
    private const FILTERS = [
        'requests' => ['search', 'certificate_type_id', 'status', 'student_id', 'academic_year_id', 'program_id', 'from', 'to'],
        'issuance' => ['search', 'certificate_type_id', 'student_id', 'from', 'to'],
        'verification' => ['search', 'certificate_type_id', 'verification_status', 'student_id', 'from', 'to'],
        'types' => ['search', 'certificate_type_id', 'from', 'to'],
        'summary' => ['certificate_type_id', 'from', 'to'],
    ];

    public const STATUS_LABELS = [
        'requested' => 'Requested',
        'generated' => 'Generated',
        'issued' => 'Issued',
    ];

    public const VERIFICATION_STATUS_LABELS = [
        'verified' => 'Verified',
        'unverified' => 'Unverified',
    ];

    public function __construct(
        private readonly CertificateReportService $reports,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CertificateReport::class);

        $requested = $request->query('report', 'requests');
        $report = is_string($requested) && array_key_exists($requested, self::REPORTS)
            ? $requested
            : 'requests';

        $filters = $this->readFilters($request, $report);
        $activeFilters = self::FILTERS[$report];

        $data = match ($report) {
            'requests' => $this->reports->requests($filters),
            'issuance' => $this->reports->issuance($filters),
            'verification' => $this->reports->verification($filters),
            'types' => $this->reports->typeWise($filters),
            'summary' => ['summary' => $this->reports->summary($filters)],
        };

        return view('certificates.reports', array_merge([
            'college' => app(TenantContext::class)->college(),
            'reports' => self::REPORTS,
            'report' => $report,
            'reportTitle' => self::REPORTS[$report],
            'filters' => $filters,
            'activeFilters' => $activeFilters,
        ], $this->filterOptions($activeFilters), $data));
    }

    /**
     * Defensively normalise query-string parameters against the real schema.
     *
     * @return array<string, mixed>
     */
    private function readFilters(Request $request, string $report): array
    {
        $from = $this->parseDate($request->query('from') ?? $request->query('date_from'));
        $to = $this->parseDate($request->query('to') ?? $request->query('date_to'));

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $typeId = $this->positiveInt($request->query('certificate_type_id'));
        if ($typeId === null && is_string($request->query('type')) && trim($request->query('type')) !== '') {
            $code = strtoupper(trim($request->query('type')));
            $typeId = CertificateType::query()->where('code', $code)->value('id');
            $typeId = $typeId !== null ? (int) $typeId : null;
        }

        $rawStatus = is_string($request->query('status')) ? strtolower(trim($request->query('status'))) : null;
        $status = $report === 'requests' && in_array($rawStatus, Certificate::STATUSES, true)
            ? $rawStatus
            : null;

        $rawVerification = $request->query('verification_status') ?? ($report === 'verification' ? $request->query('status') : null);
        $rawVerification = is_string($rawVerification) ? strtolower(trim($rawVerification)) : null;
        $verificationStatus = $report === 'verification' && array_key_exists((string) $rawVerification, self::VERIFICATION_STATUS_LABELS)
            ? $rawVerification
            : null;

        return [
            'search' => $this->cleanText($request->query('search')),
            'certificate_type_id' => $typeId,
            'status' => $status,
            'verification_status' => $verificationStatus,
            'student_id' => $this->positiveInt($request->query('student_id')),
            'academic_year_id' => $this->positiveInt($request->query('academic_year_id')),
            'program_id' => $this->positiveInt($request->query('program_id')),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    /**
     * Load only option lists required by the active report's filter bar,
     * strictly scoped to the current college.
     *
     * @param  list<string>  $activeFilters
     * @return array<string, mixed>
     */
    private function filterOptions(array $activeFilters): array
    {
        $types = in_array('certificate_type_id', $activeFilters, true)
            ? CertificateType::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'college_id', 'name', 'code', 'is_active'])
            : collect();

        return [
            'types' => $types,
            'certificateTypes' => $types,
            'statusOptions' => self::STATUS_LABELS,
            'verificationStatusOptions' => self::VERIFICATION_STATUS_LABELS,
            'students' => in_array('student_id', $activeFilters, true)
                ? Student::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->limit(300)
                    ->get(['id', 'college_id', 'student_number', 'first_name', 'middle_name', 'last_name'])
                : collect(),
            'academicYears' => in_array('academic_year_id', $activeFilters, true)
                ? AcademicYear::query()
                    ->orderByDesc('starts_on')
                    ->orderByDesc('id')
                    ->get(['id', 'college_id', 'name', 'code'])
                : collect(),
            'programs' => in_array('program_id', $activeFilters, true)
                ? Program::query()
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get(['id', 'college_id', 'name', 'code'])
                : collect(),
        ];
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 120);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $trimmed);

            return $parsed && $parsed->format('Y-m-d') === $trimmed ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
