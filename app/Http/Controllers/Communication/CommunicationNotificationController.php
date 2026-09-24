<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationNotificationService;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreCommunicationNotificationRequest;
use App\Http\Requests\Communication\UpdateCommunicationNotificationRequest;
use App\Models\CommunicationNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Internal notifications (Communication Management, Phase 1).
 *
 * In-app only — no SMS / e-mail / WhatsApp / push delivery. Lists the active
 * college's notifications with read state and filters, lets managers send /
 * edit / delete them, and lets recipients (or managers) mark them read or
 * unread. Records are resolved under the active tenant, so another college's
 * notification is always a 404.
 */
class CommunicationNotificationController extends Controller
{
    public function __construct(private readonly CommunicationNotificationService $notifications) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationNotification::class);

        $user = $request->user();
        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'read_status' => CommunicationFilters::choice($request->query('read_status'), ['read', 'unread']),
            'priority' => CommunicationFilters::choice($request->query('priority'), CommunicationNotification::PRIORITIES),
            'notification_type' => CommunicationFilters::type($request->query('notification_type')),
            'recipient_type' => CommunicationFilters::choice($request->query('recipient_type'), array_keys(NotificationRecipients::TYPES)),
            'scope' => CommunicationFilters::choice($request->query('scope'), ['mine']),
            'date_from' => CommunicationFilters::date($request->query('date_from')),
            'date_to' => CommunicationFilters::date($request->query('date_to')),
        ];

        $query = CommunicationNotification::query()
            ->with('creator:id,name')
            // Deterministic pagination: newest first, id breaks ties.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($filters['read_status'] === 'read') {
            $query->read();
        } elseif ($filters['read_status'] === 'unread') {
            $query->unread();
        }

        foreach (['priority', 'notification_type', 'recipient_type'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['scope'] === 'mine') {
            $query->forUser($user);
        }

        if ($filters['date_from']) {
            $query->where('created_at', '>=', $filters['date_from']->copy()->startOfDay());
        }

        if ($filters['date_to']) {
            $query->where('created_at', '<=', $filters['date_to']->copy()->endOfDay());
        }

        $notifications = $query->paginate((int) config('communication.per_page', 15))->withQueryString();

        return view('communication.notifications.index', [
            'notifications' => $notifications,
            'recipientLabels' => NotificationRecipients::labelsFor($notifications->items(), $this->collegeId()),
            'filters' => $filters,
            'priorities' => CommunicationNotification::PRIORITIES,
            'types' => CommunicationNotification::TYPES,
            'recipientTypes' => NotificationRecipients::TYPES,
            'myUnread' => CommunicationNotification::query()->forUser($user)->unread()->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', CommunicationNotification::class);

        return view('communication.notifications.create', [
            'notification' => new CommunicationNotification(['notification_type' => 'general']),
            'recipientOptions' => NotificationRecipients::options(app(TenantContext::class)->require()),
            'recipientTypes' => NotificationRecipients::TYPES,
            'types' => CommunicationNotification::TYPES,
            'priorities' => CommunicationNotification::PRIORITIES,
        ]);
    }

    public function store(StoreCommunicationNotificationRequest $request): RedirectResponse
    {
        $notification = $this->notifications->send(
            app(TenantContext::class)->require(),
            $request->safe()->only(['recipient_type', 'recipient_id', 'title', 'message', 'notification_type', 'priority']),
            $request->user(),
        );

        return redirect()
            ->route('notifications.show', $notification)
            ->with('success', 'Notification sent.');
    }

    public function show(string $notification): View
    {
        $model = $this->findScoped($notification);
        $this->authorize('view', $model);

        return view('communication.notifications.show', [
            'notification' => $model->load('creator:id,name'),
            'recipientLabel' => $this->recipientLabel($model),
        ]);
    }

    public function edit(string $notification): View
    {
        $model = $this->findScoped($notification);
        $this->authorize('update', $model);

        return view('communication.notifications.edit', [
            'notification' => $model,
            'recipientLabel' => $this->recipientLabel($model),
            'types' => CommunicationNotification::TYPES,
            'priorities' => CommunicationNotification::PRIORITIES,
        ]);
    }

    public function update(UpdateCommunicationNotificationRequest $request, string $notification): RedirectResponse
    {
        $model = $this->findScoped($notification);

        $model = $this->notifications->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('notifications.show', $model)
            ->with('success', 'Notification updated.');
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $model = $this->findScoped($notification);
        $this->authorize('delete', $model);

        $this->notifications->delete($model, $request->user());

        return redirect()
            ->route('notifications.index')
            ->with('success', 'Notification deleted.');
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $model = $this->findScoped($notification);
        $this->authorize('markRead', $model);

        $this->notifications->markRead($model, $request->user());

        return $this->backTo($request, $model)->with('success', 'Notification marked as read.');
    }

    public function markUnread(Request $request, string $notification): RedirectResponse
    {
        $model = $this->findScoped($notification);
        $this->authorize('markRead', $model);

        $this->notifications->markUnread($model, $request->user());

        return $this->backTo($request, $model)->with('success', 'Notification marked as unread.');
    }

    /** Mark every unread notification addressed to the current user as read. */
    public function markAllRead(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', CommunicationNotification::class);

        $count = $this->notifications->markAllReadFor($request->user(), app(TenantContext::class)->require());

        return redirect()
            ->route('notifications.index', ['scope' => 'mine'])
            ->with('success', $count === 1 ? '1 notification marked as read.' : "{$count} notifications marked as read.");
    }

    /**
     * Only two fixed destinations are allowed (never a user-supplied URL).
     */
    private function backTo(Request $request, CommunicationNotification $notification): RedirectResponse
    {
        return $request->input('return') === 'show'
            ? redirect()->route('notifications.show', $notification)
            : redirect()->route('notifications.index');
    }

    private function recipientLabel(CommunicationNotification $notification): string
    {
        return NotificationRecipients::labelFor(
            (string) $notification->recipient_type,
            NotificationRecipients::resolve((string) $notification->recipient_type, (int) $notification->recipient_id, (int) $notification->college_id),
        );
    }

    private function collegeId(): int
    {
        return (int) app(TenantContext::class)->id();
    }

    /** Explicit tenant-scoped resolution: another college's id 404s. */
    private function findScoped(string $id): CommunicationNotification
    {
        return CommunicationNotification::query()->findOrFail($id);
    }
}
