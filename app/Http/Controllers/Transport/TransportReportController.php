<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\TransportReportService;
use App\Http\Controllers\Controller;
use App\Models\{AcademicYear, Program, Section, Student, StudentTransportAssignment, StudentTransportFeeAssignment, TransportDriver, TransportReport, TransportRoute, TransportStop, Vehicle};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Transport Reports — a READ-ONLY reporting layer over the existing Transport
 * Management module (vehicles, drivers, routes / stops, student transport
 * assignments and transport fees) and its Student / Finance integrations.
 *
 * Nothing is persisted here: no report tables, no snapshots and no second copy
 * of any Transport fact — every row and every figure is read live from the
 * operational models through TransportReportService, always tenant-scoped by
 * CollegeScope, so a vehicle / driver / route / stop / assignment / fee id from
 * another college can only produce an empty report.
 *
 * The module also never re-decides a Transport or Finance rule: statuses,
 * route → stop relationships and assigned / collected / due amounts are the
 * ones the operational services stored (see TransportReportService and
 * TransportFeeService).
 *
 * There are no POST/PUT/PATCH/DELETE routes for this module, so no screen can
 * create, edit or remove a Transport record from here.
 */
class TransportReportController extends Controller
{
    /** The six reports the screen can render, in navigation order. */
    public const REPORTS = [
        'vehicle' => 'Vehicle Report',
        'driver' => 'Driver Report',
        'route_stop' => 'Route / Stop Report',
        'assignments' => 'Student Transport Assignment Report',
        'fees' => 'Transport Fee Report',
        'summary' => 'Transport Summary',
    ];

    /**
     * Filters that apply to each report; any other query parameter is ignored
     * and never enforced. Only the filters relevant to the selected report are
     * offered, so no report carries a filter it cannot use.
     */
    public const FILTERS = [
        // Fleet report: `status` is the vehicle status, `vehicle_type` the stored type.
        'vehicle' => ['vehicle_id', 'status', 'vehicle_type'],
        // Drivers: `status` is the driver status, from/to the license expiry window.
        'driver' => ['driver_id', 'status', 'from', 'to'],
        // Routes with their stops: `status` is the route status.
        'route_stop' => ['route_id', 'stop_id', 'status'],
        // Assignments: `status` is the assignment status, from/to the start-date window.
        'assignments' => ['academic_year_id', 'student_id', 'program_id', 'section_id', 'route_id', 'stop_id', 'status', 'from', 'to'],
        // Fees: `status` is the fee assignment status, from/to the effective-from window.
        'fees' => ['academic_year_id', 'student_id', 'route_id', 'stop_id', 'status', 'from', 'to'],
        // The summary aggregates the whole college: it has no filters.
        'summary' => [],
    ];

    /** Date-range meaning per report (for the labels in the filter form). */
    public const DATE_LABELS = [
        'driver' => 'License expiry',
        'assignments' => 'Start date',
        'fees' => 'Effective from',
    ];

    private const KEYS = [
        'academic_year_id', 'student_id', 'program_id', 'section_id',
        'vehicle_id', 'driver_id', 'route_id', 'stop_id', 'vehicle_type', 'status', 'from', 'to',
    ];

    private const INTEGER_KEYS = [
        'academic_year_id', 'student_id', 'program_id', 'section_id',
        'vehicle_id', 'driver_id', 'route_id', 'stop_id',
    ];

    public function index(Request $request, TransportReportService $reports): View
    {
        $this->authorize('viewAny', TransportReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'vehicle';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'driver' => $reports->drivers($filters),
            'route_stop' => $reports->routeStops($filters),
            'assignments' => $reports->assignments($filters),
            'fees' => $reports->fees($filters),
            'summary' => ['summary' => $reports->summary()],
            default => $reports->vehicles($filters),
        };

        return view('transport.reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusLabel' => self::statusLabel($report),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
        ]));
    }

    /** The selectable statuses of the selected report's `status` filter. */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'vehicle' => Vehicle::STATUSES,
            'driver' => TransportDriver::STATUSES,
            'route_stop' => TransportRoute::STATUSES,
            'assignments' => StudentTransportAssignment::STATUSES,
            'fees' => StudentTransportFeeAssignment::STATUSES,
            default => [],
        };
    }

    private static function statusLabel(string $report): string
    {
        return match ($report) {
            'vehicle' => 'Vehicle status',
            'driver' => 'Driver status',
            'route_stop' => 'Route status',
            'assignments' => 'Assignment status',
            'fees' => 'Fee status',
            default => 'Status',
        };
    }

    /**
     * Validate and normalise the query string into the service's vocabulary.
     * Only the filters of the selected report take effect; the service always
     * receives the same keys. An id from another college is a perfectly valid
     * integer here — the tenant-scoped queries simply match nothing with it.
     */
    private function filters(Request $request, string $report): array
    {
        $visible = self::FILTERS[$report];
        $uses = fn (string $key): bool => in_array($key, $visible, true);
        $statuses = self::statuses($report);

        $dateRules = $uses('from') ? ['date_format:Y-m-d'] : ['string', 'max:20'];

        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'integer', 'min:1'],
            'vehicle_id' => ['nullable', 'integer', 'min:1'],
            'driver_id' => ['nullable', 'integer', 'min:1'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'stop_id' => ['nullable', 'integer', 'min:1'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            // A vocabulary is enforced only where the selected report actually
            // uses the filter; elsewhere the parameter is ignored, not judged.
            'status' => ['nullable', $statuses === [] || ! $uses('status') ? 'string' : Rule::in($statuses)],
            'from' => ['nullable', ...$dateRules],
            'to' => ['nullable', ...$dateRules, ...($uses('from') ? ['after_or_equal:from'] : [])],
        ]);

        $filters = array_fill_keys(self::KEYS, null);
        foreach ($visible as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }

        foreach (self::INTEGER_KEYS as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (['vehicle_type', 'status'] as $key) {
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
            $options['years'] = AcademicYear::query()
                ->orderByDesc('starts_on')->orderByDesc('id')
                ->get(['id', 'name', 'code']);
        }
        if (isset($visible['route_id']) || isset($visible['stop_id'])) {
            $options['routes'] = TransportRoute::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['stop_id'])) {
            $options['stops'] = TransportStop::query()
                ->orderBy('route_id')->orderBy('sequence')->orderBy('id')
                ->get(['id', 'route_id', 'name', 'code', 'sequence']);
        }
        if (isset($visible['vehicle_id'])) {
            $options['vehicles'] = Vehicle::query()->orderBy('registration_number')->orderBy('id')->get(['id', 'registration_number']);
        }
        if (isset($visible['vehicle_type'])) {
            $options['vehicleTypes'] = Vehicle::query()
                ->whereNotNull('vehicle_type')->where('vehicle_type', '!=', '')
                ->reorder()->orderBy('vehicle_type')->distinct()->pluck('vehicle_type');
        }
        if (isset($visible['driver_id'])) {
            $options['drivers'] = TransportDriver::query()
                ->with('faculty:id,first_name,middle_name,last_name,employee_code')
                ->orderBy('license_number')->orderBy('id')
                ->get(['id', 'faculty_id', 'license_number']);
        }
        if (isset($visible['student_id'])) {
            // Students who actually use transport, derived from the existing
            // assignment rows — no second student list is invented.
            $options['students'] = Student::query()
                ->whereIn('id', StudentTransportAssignment::query()
                    ->join('student_enrollments', 'student_enrollments.id', '=', 'student_transport_assignments.student_enrollment_id')
                    ->select('student_enrollments.student_id'))
                ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name']);
        }
        if (isset($visible['program_id'])) {
            $options['programs'] = Program::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['section_id'])) {
            $options['sections'] = Section::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }

        return $options;
    }
}
