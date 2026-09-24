<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationReportService;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Http\Controllers\Controller;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\CommunicationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Communication Reports (Communication Management, Phase 2) — READ-ONLY.
 *
 * Every figure is aggregated live from the existing notices, circulars,
 * notification and communication-log records of the active college
 * (CommunicationReportService). There is no reporting table and no write
 * route: POST / PUT / DELETE are never registered for this screen.
 */
class CommunicationReportController extends Controller
{
    public function __construct(private readonly CommunicationReportService $reports) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationReport::class);

        $from = CommunicationFilters::date($request->query('date_from'));
        $to = CommunicationFilters::date($request->query('date_to'));

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $user = $request->user();
        $canLogs = $user?->can('viewAny', CommunicationLog::class) ?? false;
        $canNotifications = $user?->can('viewAny', CommunicationNotification::class) ?? false;

        $recentNotifications = $canNotifications ? $this->reports->recentNotifications(null, $from, $to) : collect();

        return view('communication.reports.index', [
            'summary' => $this->reports->summary($from, $to),
            'recentLogs' => $canLogs ? $this->reports->recentLogs(null, $from, $to) : collect(),
            'recentNotifications' => $recentNotifications,
            'recipientLabels' => NotificationRecipients::labelsFor($recentNotifications, (int) app(TenantContext::class)->id()),
            'filters' => ['date_from' => $from, 'date_to' => $to],
            'canLogs' => $canLogs,
            'canNotifications' => $canNotifications,
        ]);
    }
}
