<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Models\Circular;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\Notice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * CommunicationReportService — read-only, live aggregations for the
 * Communication Reports screen (Communication Management, Phase 2).
 *
 * Creates NO reporting table: every figure is computed from the EXISTING
 * notices, circulars, communication_notifications and communication_logs
 * records through the tenant-scoped models (CollegeScope), so numbers can
 * never drift and never leak across colleges. Each table is summarised with
 * a single conditional-aggregate query.
 */
class CommunicationReportService
{
    /**
     * Every headline figure of the report screen.
     *
     * @return array<string, mixed>
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $notices = $this->publicationCounts(Notice::query(), $from, $to);
        $circulars = $this->publicationCounts(Circular::query(), $from, $to);
        $notifications = $this->notificationCounts($from, $to);
        $sms = $this->channelCounts(CommunicationChannels::SMS, $from, $to);
        $email = $this->channelCounts(CommunicationChannels::EMAIL, $from, $to);

        return [
            'notices' => $notices,
            'circulars' => $circulars,
            'notifications' => $notifications,
            'sms' => $sms,
            'email' => $email,
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
     * total + per-status counts of a publishable table in one query.
     *
     * @return array<string, int>
     */
    private function publicationCounts(Builder $query, ?Carbon $from, ?Carbon $to): array
    {
        $base = $this->applyDates($query, $from, $to)->toBase()->selectRaw('COUNT(*) AS total');

        foreach (PublicationWorkflow::STATUSES as $status) {
            $base->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS {$status}", [$status]);
        }

        $row = $base->first();
        $counts = ['total' => (int) ($row->total ?? 0)];

        foreach (PublicationWorkflow::STATUSES as $status) {
            $counts[$status] = (int) ($row->{$status} ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function notificationCounts(?Carbon $from, ?Carbon $to): array
    {
        $row = $this->applyDates(CommunicationNotification::query(), $from, $to)
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS read_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread')
            ->selectRaw('COALESCE(SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS sent')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS delivered')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'read' => (int) ($row->read_count ?? 0),
            'unread' => (int) ($row->unread ?? 0),
            'sent' => (int) ($row->sent ?? 0),
            'delivered' => (int) ($row->delivered ?? 0),
        ];
    }

    /**
     * Log counts of one channel, by status, in a single query.
     *
     * @return array<string, int>
     */
    private function channelCounts(string $channel, ?Carbon $from, ?Carbon $to): array
    {
        $base = $this->applyDates(CommunicationLog::query(), $from, $to)
            ->channel($channel)
            ->toBase()
            ->selectRaw('COUNT(*) AS total');

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

    private function limit(?int $limit): int
    {
        return max(1, $limit ?? (int) config('communication.reports_recent_limit', 10));
    }
}
