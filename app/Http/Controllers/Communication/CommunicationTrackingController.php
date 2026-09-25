<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationDeliveryService;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Http\Controllers\Controller;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\CommunicationTracking;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Delivery / Read Tracking (Communication Management, Phase 2).
 *
 * Extends the EXISTING notification flow: the sent / delivered / read state
 * of each notification is derived from the timestamps of the same
 * communication_notifications row — nothing is duplicated and Phase 1 read /
 * unread keeps working untouched. SMS / e-mail delivery counters come from
 * the communication_logs table.
 *
 * The screen is gated by `communication_tracking.view`; recording a delivery
 * additionally needs the notification's own `notifications.update` ability,
 * so the tracking screen can never widen what a user may change.
 */
class CommunicationTrackingController extends Controller
{
    public function __construct(private readonly CommunicationDeliveryService $delivery) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationTracking::class);

        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'state' => CommunicationFilters::choice($request->query('state'), DeliveryStates::all()),
            'recipient_type' => CommunicationFilters::choice($request->query('recipient_type'), array_keys(NotificationRecipients::TYPES)),
            'date_from' => CommunicationFilters::date($request->query('date_from')),
            'date_to' => CommunicationFilters::date($request->query('date_to')),
        ];

        $query = CommunicationNotification::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($filters['state'] !== null) {
            $query->deliveryState($filters['state']);
        }

        if ($filters['recipient_type'] !== null) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        if ($filters['date_from']) {
            $query->where('created_at', '>=', $filters['date_from']->copy()->startOfDay());
        }

        if ($filters['date_to']) {
            $query->where('created_at', '<=', $filters['date_to']->copy()->endOfDay());
        }

        $notifications = $query->paginate((int) config('communication.per_page', 15))->withQueryString();

        return view('communication.tracking.index', [
            'notifications' => $notifications,
            'recipientLabels' => NotificationRecipients::labelsFor($notifications->items(), (int) app(TenantContext::class)->id()),
            'filters' => $filters,
            'states' => DeliveryStates::LABELS,
            'recipientTypes' => NotificationRecipients::TYPES,
            'totals' => $this->delivery->notificationTotals(),
            'logTotals' => $this->delivery->logTotals(),
            'canViewLogs' => $request->user()?->can('viewAny', CommunicationLog::class) ?? false,
        ]);
    }

    /**
     * Record a delivery for one notification (idempotent). The notification
     * is resolved under the active tenant, so a foreign id is a 404.
     */
    public function markDelivered(Request $request, string $notification): RedirectResponse
    {
        $this->authorize('viewAny', CommunicationTracking::class);

        $model = CommunicationNotification::query()->findOrFail($notification);
        $this->authorize('markDelivered', $model);

        $this->delivery->markDelivered($model, $request->user());

        return redirect()
            ->route('communication-tracking.index')
            ->with('success', 'Delivery recorded.');
    }
}
