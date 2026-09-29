<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationReportService;
use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CommunicationReport;
use App\Models\CommunicationTemplate;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * CommunicationReportController — single read-only entry point for the
 * Communication Reports module (REPORTS → Communication Reports).
 *
 * Strictly GET-only: renders one of the eight Communication reports selected by
 * the `?report=` query parameter and never mutates any Communication record.
 */
class CommunicationReportController extends Controller
{
    /**
     * Ordered map of report keys to human-readable report titles.
     *
     * @var array<string, string>
     */
    public const REPORTS = [
        'notices' => 'Notice Report',
        'circulars' => 'Circular Report',
        'notifications' => 'Notification Report',
        'templates' => 'Communication Template Report',
        'sms_logs' => 'SMS Log Report',
        'email_logs' => 'Email Log Report',
        'tracking' => 'Delivery / Read Tracking Report',
        'summary' => 'Communication Summary',
    ];

    /**
     * Which filter controls are meaningful for each report tab.
     *
     * @var array<string, list<string>>
     */
    private const FILTERS = [
        'notices' => ['search', 'notice_type', 'priority', 'target_type', 'department_id', 'program_id', 'section_id', 'status', 'from', 'to'],
        'circulars' => ['search', 'target_type', 'status', 'from', 'to'],
        'notifications' => ['search', 'notification_type', 'priority', 'recipient_type', 'student_id', 'faculty_id', 'user_id', 'status', 'from', 'to'],
        'templates' => ['search', 'channel', 'status', 'from', 'to'],
        'sms_logs' => ['search', 'template_id', 'recipient_type', 'student_id', 'faculty_id', 'user_id', 'status', 'from', 'to'],
        'email_logs' => ['search', 'template_id', 'recipient_type', 'student_id', 'faculty_id', 'user_id', 'status', 'from', 'to'],
        'tracking' => ['search', 'notification_type', 'priority', 'recipient_type', 'student_id', 'faculty_id', 'user_id', 'status', 'from', 'to'],
        'summary' => ['from', 'to'],
    ];

    public function __construct(
        private readonly CommunicationReportService $reports,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationReport::class);

        $requested = $request->query('report', 'notices');
        $report = is_string($requested) && array_key_exists($requested, self::REPORTS)
            ? $requested
            : 'notices';

        $filters = $this->readFilters($request, $report);
        $activeFilters = self::FILTERS[$report];

        $data = match ($report) {
            'notices' => $this->reports->notices($filters),
            'circulars' => $this->reports->circulars($filters),
            'notifications' => $this->reports->notifications($filters),
            'templates' => $this->reports->templates($filters),
            'sms_logs' => $this->reports->smsLogs($filters),
            'email_logs' => $this->reports->emailLogs($filters),
            'tracking' => $this->reports->tracking($filters),
            'summary' => ['summary' => $this->reports->summary($filters)],
        };

        return view('communication.reports.index', array_merge([
            'college' => app(TenantContext::class)->college(),
            'reports' => self::REPORTS,
            'report' => $report,
            'reportTitle' => self::REPORTS[$report],
            'filters' => $filters,
            'activeFilters' => $activeFilters,
        ], $this->filterOptions($activeFilters, $report), $data));
    }

    /**
     * Defensively normalise query-string parameters against the real schema.
     *
     * @return array<string, mixed>
     */
    private function readFilters(Request $request, string $report): array
    {
        $from = CommunicationFilters::date($request->query('from') ?? $request->query('date_from'));
        $to = CommunicationFilters::date($request->query('to') ?? $request->query('date_to'));

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $targetOptions = $report === 'circulars'
            ? array_keys(CommunicationTargets::forCirculars())
            : array_keys(CommunicationTargets::forNotices());

        return [
            'search' => CommunicationFilters::text($request->query('search')),
            'notice_type' => CommunicationFilters::type($request->query('notice_type')),
            'notification_type' => CommunicationFilters::type($request->query('notification_type')),
            'priority' => CommunicationFilters::choice($request->query('priority'), CommunicationPriority::ALL),
            'target_type' => CommunicationFilters::choice($request->query('target_type'), $targetOptions),
            'target_id' => $this->positiveInt($request->query('target_id')),
            'department_id' => $this->positiveInt($request->query('department_id')),
            'program_id' => $this->positiveInt($request->query('program_id')),
            'section_id' => $this->positiveInt($request->query('section_id')),
            'recipient_type' => CommunicationFilters::choice($request->query('recipient_type'), array_keys(NotificationRecipients::TYPES)),
            'recipient_id' => $this->positiveInt($request->query('recipient_id')),
            'student_id' => $this->positiveInt($request->query('student_id')),
            'faculty_id' => $this->positiveInt($request->query('faculty_id')),
            'user_id' => $this->positiveInt($request->query('user_id')),
            'channel' => CommunicationFilters::choice($request->query('channel'), CommunicationChannels::all()),
            'template_id' => $this->positiveInt($request->query('template_id')),
            'status' => $this->resolveStatusFilter($request, $report),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function resolveStatusFilter(Request $request, string $report): ?string
    {
        return match ($report) {
            'notices', 'circulars' => CommunicationFilters::choice($request->query('status'), PublicationWorkflow::STATUSES),
            'notifications' => CommunicationFilters::choice(
                $request->query('status') ?? $request->query('read_status'),
                ['read', 'unread']
            ),
            'templates' => CommunicationFilters::choice($request->query('status'), CommunicationTemplate::STATUSES),
            'sms_logs', 'email_logs' => CommunicationFilters::choice($request->query('status'), CommunicationLogStatus::ALL),
            'tracking' => CommunicationFilters::choice(
                $request->query('status') ?? $request->query('state'),
                DeliveryStates::all()
            ),
            default => null,
        };
    }

    /**
     * Only load option collections needed by the active report's filter bar,
     * keeping every dropdown strictly scoped to the active college.
     *
     * @param  list<string>  $activeFilters
     * @return array<string, mixed>
     */
    private function filterOptions(array $activeFilters, string $report): array
    {
        $limit = max(1, (int) config('communication.recipient_option_limit', 500));
        $college = app(TenantContext::class)->college();

        $templateQuery = fn (string $channel) => CommunicationTemplate::query()
            ->channel($channel)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'channel']);

        $priorities = [];
        foreach (CommunicationPriority::ALL as $priority) {
            $priorities[$priority] = CommunicationPriority::label($priority);
        }

        return [
            'statusOptions' => $this->statusOptionsFor($report),
            'noticeTypes' => CommunicationTypes::NOTICE_TYPES,
            'notificationTypes' => CommunicationTypes::NOTIFICATION_TYPES,
            'priorities' => $priorities,
            'targetTypes' => $report === 'circulars'
                ? CommunicationTargets::forCirculars()
                : CommunicationTargets::forNotices(),
            'recipientTypes' => NotificationRecipients::TYPES,
            'channels' => CommunicationChannels::LABELS,
            'departments' => in_array('department_id', $activeFilters, true)
                ? Department::query()->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
            'programs' => in_array('program_id', $activeFilters, true)
                ? Program::query()->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
            'sections' => in_array('section_id', $activeFilters, true)
                ? Section::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'templateOptions' => in_array('template_id', $activeFilters, true)
                ? match ($report) {
                    'sms_logs' => $templateQuery(CommunicationChannels::SMS),
                    'email_logs' => $templateQuery(CommunicationChannels::EMAIL),
                    default => CommunicationTemplate::query()->orderBy('name')->get(['id', 'name', 'code', 'channel']),
                }
                : collect(),
            'students' => in_array('student_id', $activeFilters, true)
                ? Student::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->limit($limit)
                    ->get(['id', 'student_number', 'first_name', 'last_name'])
                : collect(),
            'facultyList' => in_array('faculty_id', $activeFilters, true)
                ? Faculty::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->limit($limit)
                    ->get(['id', 'employee_code', 'first_name', 'last_name'])
                : collect(),
            'users' => in_array('user_id', $activeFilters, true) && $college
                ? $college->users()
                    ->orderBy('users.name')
                    ->limit($limit)
                    ->get(['users.id', 'users.name', 'users.email'])
                : collect(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptionsFor(string $report): array
    {
        $publicationStatuses = [];
        foreach (PublicationWorkflow::STATUSES as $status) {
            $publicationStatuses[$status] = PublicationWorkflow::label($status);
        }

        $templateStatuses = [];
        foreach (CommunicationTemplate::STATUSES as $status) {
            $templateStatuses[$status] = ucfirst($status);
        }

        $logStatuses = [];
        foreach (CommunicationLogStatus::ALL as $status) {
            $logStatuses[$status] = CommunicationLogStatus::label($status);
        }

        return match ($report) {
            'notices', 'circulars' => $publicationStatuses,
            'notifications' => [
                'unread' => 'Unread',
                'read' => 'Read',
            ],
            'templates' => $templateStatuses,
            'sms_logs', 'email_logs' => $logStatuses,
            'tracking' => DeliveryStates::LABELS,
            default => [],
        };
    }
}
