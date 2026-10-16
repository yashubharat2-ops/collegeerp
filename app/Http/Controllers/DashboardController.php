<?php

namespace App\Http\Controllers;

use App\Domain\Academic\Services\AcademicReportService;
use App\Domain\Finance\Services\FinanceReportService;
use App\Models\AcademicAttendance;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\FeePayment;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $tenantContext, FinanceReportService $financeReports): View
    {
        $user = auth()->user();
        $college = $tenantContext->college();
        $collegeId = $tenantContext->id();
        $colleges = $user->isSuperAdmin()
            ? College::query()->where('status', 'active')->orderBy('name')->get()
            : $user->colleges()->where('status', 'active')->orderBy('name')->get();

        // AcademicYearController enforces one active year per college. Terms do
        // not have date bounds or a unique current marker, so an active term is
        // not enough evidence to label one term as current.
        $academicYear = $collegeId
            ? AcademicYear::query()->where('college_id', $collegeId)->where('status', 'active')->first()
            : null;
        $maySeeAcademicYear = $collegeId !== null && $user->hasPermission('academic-years.view');

        $metrics = [];
        $studentMetricsAllowed = $collegeId !== null
            && $user->hasPermission('students.view')
            && $user->hasPermission('student_enrollments.view');
        if ($studentMetricsAllowed) {
            $students = $academicYear
                ? StudentEnrollment::query()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYear->id)
                    ->where('status', 'active')
                    ->whereHas('student')
                    ->distinct()
                    ->count('student_id')
                : null;

            $metrics[] = [
                'key' => 'students',
                'label' => 'Total students',
                'value' => $students,
                'format' => 'number',
                'note' => $academicYear
                    ? 'Distinct students with an active enrollment in the current academic year.'
                    : 'Unavailable until this college has an active academic year.',
                'url' => route('students.index'),
                'action' => 'View students',
            ];
        }

        if ($collegeId && $user->hasPermission('admissions.view')) {
            $newAdmissions = $academicYear
                ? Admission::query()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYear->id)
                    ->where('status', 'active')
                    ->count()
                : null;

            $metrics[] = [
                'key' => 'admissions',
                'label' => 'New admissions',
                'value' => $newAdmissions,
                'format' => 'number',
                'note' => $academicYear
                    ? 'Active admission records for the current academic year.'
                    : 'Unavailable until this college has an active academic year.',
                'url' => route('admissions.index'),
                'action' => 'View admissions',
            ];
        }

        if ($collegeId && $user->hasPermission('faculties.view')) {
            $metrics[] = [
                'key' => 'faculty',
                'label' => 'Active faculty & staff',
                'value' => Faculty::query()
                    ->where('college_id', $collegeId)
                    ->where('status', 'active')
                    ->count(),
                'format' => 'number',
                'note' => 'Active records; HR Employee is an alias of the same Faculty table.',
                'url' => route('employees.index'),
                'action' => 'View staff',
            ];
        }

        $canViewApplications = $collegeId && $user->hasPermission('admission_applications.view');
        $pendingApplications = null;
        if ($canViewApplications && $academicYear) {
            $pendingApplications = AdmissionApplication::query()
                ->where('college_id', $collegeId)
                ->where('academic_year_id', $academicYear->id)
                ->whereIn('status', ['submitted', 'under_review'])
                ->count();

            $metrics[] = [
                'key' => 'pending-applications',
                'label' => 'Pending applications',
                'value' => $pendingApplications,
                'format' => 'number',
                'note' => 'Submitted or under review in the current academic year.',
                'url' => route('admission-applications.index', ['academic_year_id' => $academicYear->id]),
                'action' => 'Review applications',
            ];
        } elseif ($canViewApplications) {
            $metrics[] = [
                'key' => 'pending-applications',
                'label' => 'Pending applications',
                'value' => null,
                'format' => 'number',
                'note' => 'Unavailable until this college has an active academic year.',
                'url' => route('admission-applications.index'),
                'action' => 'Review applications',
            ];
        }

        $financeSummary = null;
        if ($collegeId && $user->hasPermission('finance_reports.view')) {
            if ($academicYear) {
                // Reuse the Finance Reports ledger; it sums completed payments
                // once (the fee_payments source of truth) and derives balances
                // with the module's concession/refund rules across student,
                // transport, and hostel fee assignments.
                $financeSummary = $financeReports->summary([
                    'academic_year_id' => $academicYear->id,
                    'program_id' => null,
                ])['summary'];

                $metrics[] = [
                    'key' => 'fees-collected',
                    'label' => 'Fees collected',
                    'value' => $financeSummary['collections']['total'],
                    'format' => 'money',
                    'note' => 'Completed payments in the current academic year; refunds are reported separately.',
                    'url' => route('finance-reports.index', ['report' => 'summary', 'academic_year_id' => $academicYear->id]),
                    'action' => 'View finance summary',
                ];
                $metrics[] = [
                    'key' => 'pending-fees',
                    'label' => 'Pending fees',
                    'value' => $financeSummary['outstanding_total'],
                    'format' => 'money',
                    'note' => 'Outstanding after valid concessions, payments, and refunds.',
                    'url' => route('finance-reports.index', ['report' => 'summary', 'academic_year_id' => $academicYear->id]),
                    'action' => 'View finance summary',
                ];
            } else {
                foreach ([['fees-collected', 'Fees collected'], ['pending-fees', 'Pending fees']] as [$key, $label]) {
                    $metrics[] = [
                        'key' => $key,
                        'label' => $label,
                        'value' => null,
                        'format' => 'money',
                        'note' => 'Unavailable until this college has an active academic year.',
                        'url' => route('finance-reports.index', ['report' => 'summary']),
                        'action' => 'View finance summary',
                    ];
                }
            }
        }

        $canViewProgramAnalytics = $collegeId !== null
            && $academicYear !== null
            && $user->hasPermission('students.view')
            && $user->hasPermission('student_enrollments.view')
            && $user->hasPermission('programs.view');
        $canViewAttendance = $collegeId !== null
            && $academicYear !== null
            && ($user->hasPermission('academic_attendance.view') || $user->hasPermission('academic_reports.view'));
        $canViewAdmissionTrend = $canViewApplications && $academicYear !== null;
        $canViewFinancialAnalytics = $financeSummary !== null;
        $canViewPendingDocuments = $collegeId !== null
            && $user->hasPermission('admission_documents.view')
            && $user->hasPermission('admission_documents.verify');
        $canViewUpcomingExams = $collegeId !== null
            && $academicYear !== null
            && $user->hasPermission('exam_schedules.view');
        $attendanceReportUrl = $user->hasPermission('academic_reports.view')
            ? route('academic-reports.index', ['report' => 'student_attendance', 'academic_year_id' => $academicYear?->id, 'group' => 'student'])
            : route('academic-attendance.index');

        $programCounts = collect();
        $attendance = null;
        $admissionTrend = [];
        $collectionTrend = [];
        if ($collegeId && $academicYear) {
            if ($canViewProgramAnalytics) {
                $programCounts = StudentEnrollment::query()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYear->id)
                    ->where('status', 'active')
                    ->whereHas('student')
                    ->select('program_id')
                    ->selectRaw('COUNT(DISTINCT student_id) AS students_count')
                    ->groupBy('program_id')
                    ->with('program:id,name')
                    ->orderByDesc('students_count')
                    ->orderBy('program_id')
                    ->get()
                    ->map(fn (StudentEnrollment $enrollment): array => [
                        'program' => $enrollment->program?->name ?? 'Program not assigned',
                        'students' => (int) $enrollment->students_count,
                    ]);
            }

            if ($canViewAttendance) {
                $attendanceRow = AcademicAttendance::query()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYear->id)
                    ->selectRaw('COUNT(*) AS recorded_sessions, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS attended_sessions', AcademicReportService::ATTENDED_STATUSES)
                    ->first();
                $recorded = (int) ($attendanceRow?->recorded_sessions ?? 0);
                $attended = (int) ($attendanceRow?->attended_sessions ?? 0);
                $attendance = [
                    'recorded' => $recorded,
                    'attended' => $attended,
                    'percentage' => $recorded > 0 ? round($attended * 100 / $recorded, 1) : null,
                ];
            }

            if ($canViewAdmissionTrend) {
                $chartStart = CarbonImmutable::now()->startOfMonth()->subMonths(5);
                $chartEnd = CarbonImmutable::now()->endOfDay();
                $monthExpression = $this->monthExpression('submitted_at');
                $submissionRows = AdmissionApplication::query()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYear->id)
                    ->whereNotNull('submitted_at')
                    ->whereBetween('submitted_at', [$chartStart, $chartEnd])
                    ->selectRaw($monthExpression.' AS month, COUNT(*) AS total')
                    ->groupByRaw($monthExpression)
                    ->get()
                    ->keyBy('month');

                $admissionTrend = $this->sixMonthSeries($submissionRows, 'total');
            }

            if ($canViewFinancialAnalytics) {
                $chartStart = CarbonImmutable::now()->startOfMonth()->subMonths(5);
                $chartEnd = CarbonImmutable::now()->toDateString();
                $monthExpression = $this->monthExpression('fee_payments.payment_date');
                $paymentRows = FeePayment::query()
                    ->where('fee_payments.college_id', $collegeId)
                    ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
                    ->whereHas('studentEnrollment', fn ($query) => $query->where('academic_year_id', $academicYear->id))
                    ->whereBetween('fee_payments.payment_date', [$chartStart->toDateString(), $chartEnd])
                    ->selectRaw($monthExpression.' AS month, COALESCE(SUM(fee_payments.amount), 0) AS total')
                    ->groupByRaw($monthExpression)
                    ->get()
                    ->keyBy('month');

                $collectionTrend = $this->sixMonthSeries($paymentRows, 'total', true);
            }
        }

        $actions = [];
        if ($canViewApplications && $academicYear && $pendingApplications > 0) {
            $actions[] = [
                'label' => 'Applications need review',
                'note' => 'Submitted or under review in the current academic year.',
                'count' => $pendingApplications,
                'url' => route('admission-applications.index', ['academic_year_id' => $academicYear->id]),
            ];
        }

        if ($canViewPendingDocuments) {
            $pendingDocuments = AdmissionDocument::query()
                ->where('college_id', $collegeId)
                ->where('verification_status', 'pending')
                ->count();
            if ($pendingDocuments > 0) {
                $actions[] = [
                    'label' => 'Documents awaiting verification',
                    'note' => 'College-wide; admission documents have no academic-year column.',
                    'count' => $pendingDocuments,
                    'url' => route('admission-documents.index', ['verification_status' => 'pending']),
                ];
            }
        }

        $upcomingExams = collect();
        if ($canViewUpcomingExams) {
            $upcomingExams = ExamSchedule::query()
                ->where('college_id', $collegeId)
                ->where('academic_year_id', $academicYear->id)
                ->where('status', ExamSchedule::STATUS_SCHEDULED)
                ->whereDate('exam_date', '>=', today())
                ->with(['examination:id,name', 'subject:id,name'])
                ->orderBy('exam_date')
                ->orderBy('start_time')
                ->limit(4)
                ->get(['id', 'examination_id', 'subject_id', 'exam_date', 'start_time']);
        }

        $actionCenterAvailable = ($canViewApplications && $academicYear !== null)
            || $canViewPendingDocuments
            || $canViewUpcomingExams;
        $hasActivityPermission = $collegeId !== null && $user->hasPermission('audit_logs.view');
        $recentActivity = collect();
        if ($hasActivityPermission) {
            $recentActivity = AuditLog::query()
                ->where('college_id', $collegeId)
                ->select(['id', 'user_id', 'college_id', 'action', 'created_at'])
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'action' => Str::headline($log->action),
                    'user' => $log->user?->name ?? 'System',
                    'created_at' => $log->created_at,
                ]);
        }

        return view('dashboard.index', [
            'colleges' => $colleges,
            'college' => $college,
            'academicYear' => $maySeeAcademicYear ? $academicYear : null,
            'currentAcademicYearId' => $academicYear?->id,
            'metrics' => $metrics,
            'programCounts' => $programCounts,
            'attendance' => $attendance,
            'admissionTrend' => $admissionTrend,
            'collectionTrend' => $collectionTrend,
            'actions' => $actions,
            'upcomingExams' => $upcomingExams,
            'recentActivity' => $recentActivity,
            'canViewAcademicYear' => $maySeeAcademicYear,
            'hasActiveYear' => $academicYear !== null,
            'canViewProgramAnalytics' => $canViewProgramAnalytics,
            'canViewAttendance' => $canViewAttendance,
            'attendanceReportUrl' => $attendanceReportUrl,
            'canViewAdmissionTrend' => $canViewAdmissionTrend,
            'canViewFinancialAnalytics' => $canViewFinancialAnalytics,
            'actionCenterAvailable' => $actionCenterAvailable,
            'canViewApplications' => (bool) $canViewApplications,
            'canViewUpcomingExams' => $canViewUpcomingExams,
            'hasActivityPermission' => $hasActivityPermission,
        ]);
    }

    /** Use a native month formatter for the database connection in use. */
    private function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM')",
            'sqlsrv' => "CONVERT(char(7), {$column}, 126)",
            default => "SUBSTRING({$column}, 1, 7)",
        };
    }

    /** Build a real six-month series, filling only genuinely empty months with zero. */
    private function sixMonthSeries(Collection $rows, string $valueColumn, bool $money = false): array
    {
        $cursor = CarbonImmutable::now()->startOfMonth()->subMonths(5);
        $series = [];
        for ($month = 0; $month < 6; $month++) {
            $key = $cursor->format('Y-m');
            $value = $rows->get($key)?->{$valueColumn} ?? 0;
            $series[] = [
                'key' => $key,
                'label' => $cursor->format('M'),
                'value' => $money ? round((float) $value, 2) : (int) $value,
            ];
            $cursor = $cursor->addMonth();
        }

        return $series;
    }
}
