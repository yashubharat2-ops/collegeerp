<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CommunicationDeliveryService — delivery / read tracking (Communication
 * Management, Phase 2).
 *
 * Extends the EXISTING notification flow instead of duplicating it: the
 * state of a notification is derived from the timestamps stored on the very
 * same `communication_notifications` row (sent_at → delivered_at →
 * read_at). No tracking table is created and no notification record is
 * copied. Phase 1 read / unread keeps working exactly as before.
 *
 * Every write is tenant-guarded, transactional, idempotent and audited.
 */
class CommunicationDeliveryService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** Stamp the moment a notification left the system (idempotent). */
    public function markSent(CommunicationNotification $notification): CommunicationNotification
    {
        $this->assertTenant($notification);

        if ($notification->sent_at !== null) {
            return $notification;
        }

        return $this->stamp($notification, ['sent_at' => Carbon::now()], 'notifications.sent');
    }

    /** Record a delivery (idempotent; also back-fills sent_at). */
    public function markDelivered(CommunicationNotification $notification, ?User $actor = null): CommunicationNotification
    {
        $this->assertTenant($notification);

        if ($notification->delivered_at !== null) {
            return $notification;
        }

        $now = Carbon::now();

        return $this->stamp($notification, [
            'sent_at' => $notification->sent_at ?? $now,
            'delivered_at' => $now,
        ], 'notifications.delivered');
    }

    /**
     * Read implies delivered: called by the Phase 1 read flow so an already
     * read notification is never shown as undelivered. Returns the
     * attributes to persist alongside read_at.
     *
     * @return array<string, mixed>
     */
    public function deliveryAttributesForRead(CommunicationNotification $notification, Carbon $readAt): array
    {
        $attributes = [];

        if ($notification->sent_at === null) {
            $attributes['sent_at'] = $notification->created_at ?? $readAt;
        }

        if ($notification->delivered_at === null) {
            $attributes['delivered_at'] = $readAt;
        }

        return $attributes;
    }

    /**
     * Live tracking counters of the active college.
     *
     * @return array<string, int>
     */
    public function notificationTotals(): array
    {
        $row = CommunicationNotification::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS sent')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS delivered')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS read_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread')
            ->selectRaw('COALESCE(SUM(CASE WHEN delivered_at IS NULL AND read_at IS NULL THEN 1 ELSE 0 END), 0) AS undelivered')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            DeliveryStates::SENT => (int) ($row->sent ?? 0),
            DeliveryStates::DELIVERED => (int) ($row->delivered ?? 0),
            DeliveryStates::READ => (int) ($row->read_count ?? 0),
            'unread' => (int) ($row->unread ?? 0),
            'undelivered' => (int) ($row->undelivered ?? 0),
        ];
    }

    /**
     * Live delivery counters of the SMS / e-mail logs.
     *
     * @return array<string, int>
     */
    public function logTotals(): array
    {
        $base = CommunicationLog::query()->toBase()->selectRaw('COUNT(*) AS total');

        foreach (CommunicationLogStatus::ALL as $status) {
            $base->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS {$status}", [$status]);
        }

        $row = $base->first();
        $totals = ['total' => (int) ($row->total ?? 0)];

        foreach (CommunicationLogStatus::ALL as $status) {
            $totals[$status] = (int) ($row->{$status} ?? 0);
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stamp(CommunicationNotification $notification, array $attributes, string $action): CommunicationNotification
    {
        return DB::transaction(function () use ($notification, $attributes, $action): CommunicationNotification {
            $fresh = CommunicationNotification::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $notification->college_id)
                ->whereKey($notification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = ['sent_at' => $fresh->sent_at, 'delivered_at' => $fresh->delivered_at];

            foreach ($attributes as $column => $value) {
                $fresh->{$column} = $value;
            }

            if ($before == ['sent_at' => $fresh->sent_at, 'delivered_at' => $fresh->delivered_at]) {
                return $fresh; // nothing changed: no write, no audit
            }

            $fresh->save();

            $this->audit->record($action, $fresh, $before, [
                'sent_at' => $fresh->sent_at,
                'delivered_at' => $fresh->delivered_at,
            ]);

            return $fresh->refresh();
        });
    }

    private function assertTenant(CommunicationNotification $notification): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === (int) $notification->college_id, 403);
    }
}
