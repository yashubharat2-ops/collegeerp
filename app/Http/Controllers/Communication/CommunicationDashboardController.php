<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationDashboardService;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Http\Controllers\Controller;
use App\Models\Circular;
use App\Models\CommunicationDashboard;
use App\Models\CommunicationNotification;
use App\Models\Notice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Communication Dashboard (Communication Management, Phase 1).
 *
 * Read-only overview of notices, circulars and internal notifications. Every
 * figure is aggregated live through the tenant-scoped models by
 * CommunicationDashboardService — there are no dashboard tables and nothing
 * on the screen writes. Access is gated by `communication_dashboard.view`;
 * each "recent" panel is additionally gated on its module's view permission
 * so the dashboard never reveals titles a user could not open.
 */
class CommunicationDashboardController extends Controller
{
    public function __invoke(Request $request, CommunicationDashboardService $dashboard): View
    {
        $this->authorize('viewAny', CommunicationDashboard::class);

        $user = $request->user();
        $canNotices = $user->can('viewAny', Notice::class);
        $canCirculars = $user->can('viewAny', Circular::class);
        $canNotifications = $user->can('viewAny', CommunicationNotification::class);

        $recentNotifications = $canNotifications ? $dashboard->recentNotifications() : collect();

        return view('communication.dashboard', [
            'totals' => $dashboard->totals($user),
            'recentNotices' => $canNotices ? $dashboard->recentNotices() : collect(),
            'recentCirculars' => $canCirculars ? $dashboard->recentCirculars() : collect(),
            'recentNotifications' => $recentNotifications,
            'recipientLabels' => NotificationRecipients::labelsFor($recentNotifications, (int) app(TenantContext::class)->id()),
            'canNotices' => $canNotices,
            'canCirculars' => $canCirculars,
            'canNotifications' => $canNotifications,
        ]);
    }
}
