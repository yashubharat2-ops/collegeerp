<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\PublicationWorkflow;
use App\Models\Circular;
use App\Models\CommunicationNotification;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * CommunicationDashboardService — read-only, live aggregations for the
 * Communication Dashboard (Communication Management, Phase 1).
 *
 * Creates NO dashboard or summary tables: every figure is computed from the
 * notices, circulars and communication_notifications records through the
 * tenant-scoped models (CollegeScope), so the numbers can never drift from
 * the records and never leak across colleges. Archived (soft-deleted)
 * notices and circulars are excluded automatically. Each table is counted
 * with a single conditional-aggregate query.
 */
class CommunicationDashboardService
{
    /**
     * Headline counters for the active college.
     *
     * @return array<string, int>
     */
    public function totals(?User $viewer = null): array
    {
        $notices = $this->statusCounts(Notice::query());
        $circulars = $this->statusCounts(Circular::query());

        $notifications = CommunicationNotification::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread')
            ->first();

        return [
            'notices' => $notices['total'],
            'published_notices' => $notices[PublicationWorkflow::PUBLISHED],
            'draft_notices' => $notices[PublicationWorkflow::DRAFT],
            'archived_notices' => $notices[PublicationWorkflow::ARCHIVED],
            'live_notices' => Notice::query()->live()->count(),
            'circulars' => $circulars['total'],
            'published_circulars' => $circulars[PublicationWorkflow::PUBLISHED],
            'draft_circulars' => $circulars[PublicationWorkflow::DRAFT],
            'archived_circulars' => $circulars[PublicationWorkflow::ARCHIVED],
            'notifications' => (int) ($notifications->total ?? 0),
            'unread_notifications' => (int) ($notifications->unread ?? 0),
            'my_unread_notifications' => $viewer
                ? CommunicationNotification::query()->forUser($viewer)->unread()->count()
                : 0,
        ];
    }

    /**
     * @return Collection<int, Notice>
     */
    public function recentNotices(?int $limit = null): Collection
    {
        return Notice::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @return Collection<int, Circular>
     */
    public function recentCirculars(?int $limit = null): Collection
    {
        return Circular::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @return Collection<int, CommunicationNotification>
     */
    public function recentNotifications(?int $limit = null): Collection
    {
        return CommunicationNotification::query()
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
    private function statusCounts(Builder $query): array
    {
        $base = $query->toBase()->selectRaw('COUNT(*) AS total');

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

    private function limit(?int $limit): int
    {
        return max(1, $limit ?? (int) config('communication.dashboard_recent_limit', 5));
    }
}
