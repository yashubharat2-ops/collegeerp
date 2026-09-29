<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Models\Circular;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\CommunicationTemplate;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Notice;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * CommunicationReportService — the READ side of the Communication Reports
 * module (REPORTS → Communication Reports).
 *
 * Eight live reports over the EXISTING Communication records of the active
 * college:
 *
 *   1. Notice Report                   → notices
 *   2. Circular Report                 → circulars
 *   3. Notification Report             → communication_notifications
 *   4. Communication Template Report   → communication_templates (+ log counts)
 *   5. SMS Log Report                  → communication_logs (channel = sms)
 *   6. Email Log Report                → communication_logs (channel = email)
 *   7. Delivery / Read Tracking Report → communication_notifications tracking
 *   8. Communication Summary           → live college-wide aggregates
 *
 * Creates NO reporting table and writes NOTHING: every row and every counter
 * is read live through the tenant-scoped Eloquent models (CollegeScope), so
 * figures never drift from operational data and never leak across colleges.
 */
class CommunicationReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    /**
     * Report 1 — Notice Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     notices: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     targetLabels: array<int, string>
     * }
     */
    public function notices(array $filters = []): array
    {
        $query = $this->noticeQuery($filters);

        $rows = (clone $query)
            ->with('creator:id,name')
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'notices' => $rows,
            'totals' => $this->publicationCounts(clone $query),
            'targetLabels' => $this->targetLabelsFor($rows->items(), $this->collegeId()),
        ];
    }

    /**
     * Report 2 — Circular Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     circulars: LengthAwarePaginator,
     *     totals: array<string, int>
     * }
     */
    public function circulars(array $filters = []): array
    {
        $query = $this->circularQuery($filters);

        $rows = (clone $query)
            ->with('creator:id,name')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'circulars' => $rows,
            'totals' => $this->publicationCounts(clone $query),
        ];
    }

    /**
     * Report 3 — Notification Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     notifications: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     recipientLabels: array<string, string>
     * }
     */
    public function notifications(array $filters = []): array
    {
        $query = $this->notificationQuery($filters, false);

        $rows = (clone $query)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'notifications' => $rows,
            'totals' => $this->notificationCountsFromBuilder(clone $query),
            'recipientLabels' => NotificationRecipients::labelsFor($rows->items(), $this->collegeId()),
        ];
    }

    /**
     * Report 4 — Communication Template Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     templates: LengthAwarePaginator,
     *     totals: array<string, int>
     * }
     */
    public function templates(array $filters = []): array
    {
        $query = $this->templateQuery($filters);

        $rows = (clone $query)
            ->with('creator:id,name')
            ->withCount([
                'logs',
                'logs as delivered_logs_count' => fn (Builder $q) => $q->where('status', CommunicationLogStatus::DELIVERED),
                'logs as failed_logs_count' => fn (Builder $q) => $q->where('status', CommunicationLogStatus::FAILED),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'templates' => $rows,
            'totals' => $this->templateCountsFromBuilder(clone $query),
        ];
    }

    /**
     * Report 5 — SMS Log Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     logs: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     recipientLabels: array<string, string>
     * }
     */
    public function smsLogs(array $filters = []): array
    {
        return $this->channelLogs(CommunicationChannels::SMS, $filters);
    }

    /**
     * Report 6 — Email Log Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     logs: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     recipientLabels: array<string, string>
     * }
     */
    public function emailLogs(array $filters = []): array
    {
        return $this->channelLogs(CommunicationChannels::EMAIL, $filters);
    }

    /**
     * Report 7 — Delivery / Read Tracking Report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     notifications: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     logTotals: array<string, int>,
     *     recipientLabels: array<string, string>
     * }
     */
    public function tracking(array $filters = []): array
    {
        $query = $this->notificationQuery($filters, true);

        $rows = (clone $query)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        [$from, $to] = $this->resolveDateWindow($filters);
        $logQuery = $this->applyDates(CommunicationLog::query(), $from, $to);

        return [
            'rows' => $rows,
            'notifications' => $rows,
            'totals' => $this->notificationCountsFromBuilder(clone $query),
            'logTotals' => $this->logCountsFromBuilder($logQuery),
            'recipientLabels' => NotificationRecipients::labelsFor($rows->items(), $this->collegeId()),
        ];
    }

    /**
     * Report 8 — Communication Summary: every headline figure of the report
     * screen, aggregated live for the active college.
     *
     * Accepts either a filters array or optional Carbon start/end dates.
     *
     * @param  Carbon|array<string, mixed>|null  $from
     * @return array<string, mixed>
     */
    public function summary(Carbon|array|null $from = null, ?Carbon $to = null): array
    {
        if (is_array($from)) {
            [$from, $to] = $this->resolveDateWindow($from);
        }

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $notices = $this->publicationCounts($this->applyDates(Notice::query(), $from, $to));
        $circulars = $this->publicationCounts($this->applyDates(Circular::query(), $from, $to));
        $notifications = $this->notificationCounts($from, $to);
        $templates = $this->templateCountsFromBuilder($this->applyDates(CommunicationTemplate::query(), $from, $to));
        $sms = $this->channelCounts(CommunicationChannels::SMS, $from, $to);
        $email = $this->channelCounts(CommunicationChannels::EMAIL, $from, $to);

        return [
            'notices' => $notices,
            'circulars' => $circulars,
            'notifications' => $notifications,
            'templates' => $templates,
            'sms' => $sms,
            'email' => $email,
            'delivered_communications' => $sms[CommunicationLogStatus::DELIVERED] + $email[CommunicationLogStatus::DELIVERED],
            'failed_communications' => $sms[CommunicationLogStatus::FAILED] + $email[CommunicationLogStatus::FAILED],
            'total_communications' => $sms['total'] + $email['total'],
        ];
    }

    /**
     * Recent SMS / e-mail activity of the active college.
     *
     * @return Collection<int, CommunicationLog>
     */
    public function recentLogs(?int $limit = null, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->applyDates(CommunicationLog::query(), $from, $to)
            ->with('template:id,name,code')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * Recent internal notification activity of the active college.
     *
     * @return Collection<int, CommunicationNotification>
     */
    public function recentNotifications(?int $limit = null, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->applyDates(CommunicationNotification::query(), $from, $to)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * Resolve audience target labels for a page of notices with at most one
     * query per entity target type (department, program, section).
     *
     * @param  iterable<int, Notice>  $notices
     * @return array<int, string> keyed by notice id
     */
    public function targetLabelsFor(iterable $notices, int $collegeId): array
    {
        $idsByType = [];
        foreach ($notices as $notice) {
            $type = (string) $notice->target_type;
            if (CommunicationTargets::requiresEntity($type) && $notice->target_id) {
                $idsByType[$type][] = (int) $notice->target_id;
            }
        }

        $entities = [];
        foreach ($idsByType as $type => $ids) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = CommunicationTargets::ENTITIES[$type]['model'];
            $entities[$type] = $model::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->whereIn('id', array_values(array_unique($ids)))
                ->get(['id', 'college_id', 'name', 'code'])
                ->keyBy('id');
        }

        $labels = [];
        foreach ($notices as $notice) {
            $type = (string) $notice->target_type;
            if (! CommunicationTargets::requiresEntity($type)) {
                $labels[(int) $notice->id] = CommunicationTargets::label($type);
                continue;
            }

            $entity = $notice->target_id ? ($entities[$type][(int) $notice->target_id] ?? null) : null;
            $labels[(int) $notice->id] = CommunicationTargets::ENTITIES[$type]['label'].': '.CommunicationTargets::entityLabel($entity);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator,
     *     logs: LengthAwarePaginator,
     *     totals: array<string, int>,
     *     recipientLabels: array<string, string>
     * }
     */
    private function channelLogs(string $channel, array $filters): array
    {
        $query = $this->logQuery($channel, $filters);

        $rows = (clone $query)
            ->with(['template:id,name,code', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $linked = [];
        foreach ($rows->items() as $log) {
            if ($log->recipient_type && $log->recipient_id) {
                $linked[] = $log;
            }
        }

        return [
            'rows' => $rows,
            'logs' => $rows,
            'totals' => $this->logCountsFromBuilder(clone $query),
            'recipientLabels' => NotificationRecipients::labelsFor($linked, $this->collegeId()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function noticeQuery(array $filters): Builder
    {
        $query = Notice::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'priority', 'notice_type', 'target_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['department_id'])) {
            $query->where('target_type', CommunicationTargets::DEPARTMENT)
                ->whereIn('target_id', Department::query()->whereKey((int) $filters['department_id'])->select('id'));
        }

        if (! empty($filters['program_id'])) {
            $query->where('target_type', CommunicationTargets::PROGRAM)
                ->whereIn('target_id', Program::query()->whereKey((int) $filters['program_id'])->select('id'));
        }

        if (! empty($filters['section_id'])) {
            $query->where('target_type', CommunicationTargets::SECTION)
                ->whereIn('target_id', Section::query()->whereKey((int) $filters['section_id'])->select('id'));
        }

        if (! empty($filters['target_id']) && CommunicationTargets::requiresEntity($filters['target_type'] ?? null)) {
            $type = (string) $filters['target_type'];
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = CommunicationTargets::ENTITIES[$type]['model'];
            $query->whereIn('target_id', $model::query()->whereKey((int) $filters['target_id'])->select('id'));
        }

        [$from, $to] = $this->resolveDateWindow($filters);
        if ($from) {
            $query->where('publish_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $query->where('publish_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function circularQuery(array $filters): Builder
    {
        $query = Circular::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('circular_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'target_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        [$from, $to] = $this->resolveDateWindow($filters);
        if ($from) {
            $query->where('issue_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('issue_date', '<=', $to->toDateString());
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function notificationQuery(array $filters, bool $trackingMode): Builder
    {
        $query = CommunicationNotification::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($trackingMode) {
            $state = $filters['status'] ?? $filters['state'] ?? null;
            if (is_string($state) && DeliveryStates::isValid($state)) {
                $query->deliveryState($state);
            }
        } else {
            $readStatus = $filters['status'] ?? $filters['read_status'] ?? null;
            if ($readStatus === 'read') {
                $query->read();
            } elseif ($readStatus === 'unread') {
                $query->unread();
            }
        }

        foreach (['priority', 'notification_type', 'recipient_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        $this->applyRecipientEntityFilters($query, $filters);

        [$from, $to] = $this->resolveDateWindow($filters);

        return $this->applyDates($query, $from, $to);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function templateQuery(array $filters): Builder
    {
        $query = CommunicationTemplate::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        foreach (['channel', 'status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['template_id'])) {
            $query->whereKey((int) $filters['template_id']);
        }

        [$from, $to] = $this->resolveDateWindow($filters);

        return $this->applyDates($query, $from, $to);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function logQuery(string $channel, array $filters): Builder
    {
        $query = CommunicationLog::query()->channel($channel);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search, $channel): void {
                $q->where('recipient', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('provider_reference', 'like', "%{$search}%")
                    ->orWhere('failure_reason', 'like', "%{$search}%");

                if ($channel === CommunicationChannels::EMAIL) {
                    $q->orWhere('subject', 'like', "%{$search}%");
                }
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['template_id'])) {
            $query->whereIn(
                'communication_template_id',
                CommunicationTemplate::query()->whereKey((int) $filters['template_id'])->select('id')
            );
        }

        if (! empty($filters['recipient_type'])) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        $this->applyRecipientEntityFilters($query, $filters);

        [$from, $to] = $this->resolveDateWindow($filters);

        return $this->applyDates($query, $from, $to);
    }

    /**
     * Apply tenant-scoped recipient record filters (`student_id`, `faculty_id`,
     * `user_id`, `recipient_id`) to a notification or communication-log query.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyRecipientEntityFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['student_id'])) {
            $query->where('recipient_type', NotificationRecipients::STUDENT)
                ->whereIn('recipient_id', Student::query()->whereKey((int) $filters['student_id'])->select('id'));
        }

        if (! empty($filters['faculty_id'])) {
            $query->where('recipient_type', NotificationRecipients::STAFF)
                ->whereIn('recipient_id', Faculty::query()->whereKey((int) $filters['faculty_id'])->select('id'));
        }

        if (! empty($filters['user_id'])) {
            $college = app(TenantContext::class)->college();
            $userId = (int) $filters['user_id'];
            if ($college) {
                $query->where('recipient_type', NotificationRecipients::USER)
                    ->whereIn('recipient_id', $college->users()->whereKey($userId)->select('users.id'));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['recipient_id'])) {
            $query->where('recipient_id', (int) $filters['recipient_id']);
        }
    }

    /**
     * total + per-status + live counts of a publishable table in one query.
     *
     * @return array<string, int>
     */
    private function publicationCounts(Builder $query): array
    {
        $now = now();
        $base = $query->reorder()->toBase()->selectRaw('COUNT(*) AS total');

        foreach (PublicationWorkflow::STATUSES as $status) {
            $base->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS {$status}", [$status]);
        }

        $base->selectRaw(
            'COALESCE(SUM(CASE WHEN status = ? AND (publish_at IS NULL OR publish_at <= ?) AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END), 0) AS live',
            [PublicationWorkflow::PUBLISHED, $now, $now]
        );

        $row = $base->first();
        $counts = ['total' => (int) ($row->total ?? 0)];

        foreach (PublicationWorkflow::STATUSES as $status) {
            $counts[$status] = (int) ($row->{$status} ?? 0);
        }

        $counts['live'] = (int) ($row->live ?? 0);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function notificationCounts(?Carbon $from, ?Carbon $to): array
    {
        return $this->notificationCountsFromBuilder(
            $this->applyDates(CommunicationNotification::query(), $from, $to)
        );
    }

    /**
     * @return array<string, int>
     */
    private function notificationCountsFromBuilder(Builder $query): array
    {
        $row = $query->reorder()->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN sent_at IS NULL AND delivered_at IS NULL AND read_at IS NULL THEN 1 ELSE 0 END), 0) AS pending_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS sent')
            ->selectRaw('COALESCE(SUM(CASE WHEN sent_at IS NOT NULL AND delivered_at IS NULL AND read_at IS NULL THEN 1 ELSE 0 END), 0) AS sent_only')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS delivered')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL AND read_at IS NULL THEN 1 ELSE 0 END), 0) AS delivered_only')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS read_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NULL AND read_at IS NULL THEN 1 ELSE 0 END), 0) AS undelivered')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            DeliveryStates::PENDING => (int) ($row->pending_count ?? 0),
            DeliveryStates::SENT => (int) ($row->sent ?? 0),
            'sent_only' => (int) ($row->sent_only ?? 0),
            DeliveryStates::DELIVERED => (int) ($row->delivered ?? 0),
            'delivered_only' => (int) ($row->delivered_only ?? 0),
            DeliveryStates::READ => (int) ($row->read_count ?? 0),
            'unread' => (int) ($row->unread ?? 0),
            'undelivered' => (int) ($row->undelivered ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function templateCountsFromBuilder(Builder $query): array
    {
        $row = $query->reorder()->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive")
            ->selectRaw('COALESCE(SUM(CASE WHEN channel = ? THEN 1 ELSE 0 END), 0) AS sms', [CommunicationChannels::SMS])
            ->selectRaw('COALESCE(SUM(CASE WHEN channel = ? THEN 1 ELSE 0 END), 0) AS email', [CommunicationChannels::EMAIL])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'sms' => (int) ($row->sms ?? 0),
            'email' => (int) ($row->email ?? 0),
        ];
    }

    /**
     * Log counts of one channel, by status, in a single query.
     *
     * @return array<string, int>
     */
    private function channelCounts(string $channel, ?Carbon $from, ?Carbon $to): array
    {
        return $this->logCountsFromBuilder(
            $this->applyDates(CommunicationLog::query(), $from, $to)->channel($channel)
        );
    }

    /**
     * @return array<string, int>
     */
    private function logCountsFromBuilder(Builder $query): array
    {
        $base = $query->reorder()->toBase()->selectRaw('COUNT(*) AS total');

        foreach (CommunicationLogStatus::ALL as $status) {
            $base->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS {$status}", [$status]);
        }

        $row = $base->first();
        $counts = ['total' => (int) ($row->total ?? 0)];

        foreach (CommunicationLogStatus::ALL as $status) {
            $counts[$status] = (int) ($row->{$status} ?? 0);
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateWindow(array $filters): array
    {
        $from = $filters['date_from'] ?? null;
        if (! $from instanceof Carbon) {
            $from = CommunicationFilters::date($filters['from'] ?? $filters['date_from'] ?? null);
        }

        $to = $filters['date_to'] ?? null;
        if (! $to instanceof Carbon) {
            $to = CommunicationFilters::date($filters['to'] ?? $filters['date_to'] ?? null);
        }

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function applyDates(Builder $query, ?Carbon $from, ?Carbon $to): Builder
    {
        if ($from) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }

        if ($to) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    private function collegeId(): int
    {
        return (int) app(TenantContext::class)->id();
    }

    private function limit(?int $limit): int
    {
        return max(1, $limit ?? (int) config('communication.reports_recent_limit', 10));
    }
}
