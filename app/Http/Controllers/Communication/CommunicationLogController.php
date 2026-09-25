<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Http\Controllers\Controller;
use App\Models\CommunicationLog;
use App\Models\CommunicationTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SMS / e-mail logs (Communication Management, Phase 2) — READ-ONLY.
 *
 * Logs are immutable from the UI: only `index` and `show` are registered, so
 * no POST / PUT / DELETE route exists for them. Rows are written by
 * CommunicationLogService. Phase 2 contacts no external gateway.
 */
class CommunicationLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationLog::class);

        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'channel' => CommunicationFilters::choice($request->query('channel'), CommunicationChannels::all()),
            'status' => CommunicationFilters::choice($request->query('status'), CommunicationLogStatus::ALL),
            'template_id' => is_numeric($request->query('template_id')) ? (int) $request->query('template_id') : null,
            'date_from' => CommunicationFilters::date($request->query('date_from')),
            'date_to' => CommunicationFilters::date($request->query('date_to')),
        ];

        $query = CommunicationLog::query()
            ->with(['template:id,name,code', 'creator:id,name'])
            // Deterministic pagination: newest first, id breaks ties.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('recipient', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('provider_reference', 'like', "%{$search}%");
            });
        }

        foreach (['channel', 'status'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['template_id']) {
            $query->where('communication_template_id', $filters['template_id']);
        }

        if ($filters['date_from']) {
            $query->where('created_at', '>=', $filters['date_from']->copy()->startOfDay());
        }

        if ($filters['date_to']) {
            $query->where('created_at', '<=', $filters['date_to']->copy()->endOfDay());
        }

        return view('communication.logs.index', [
            'logs' => $query->paginate((int) config('communication.per_page', 15))->withQueryString(),
            'filters' => $filters,
            'channels' => CommunicationChannels::LABELS,
            'statuses' => CommunicationLogStatus::ALL,
            'templates' => CommunicationTemplate::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function show(string $communicationLog): View
    {
        $log = CommunicationLog::query()->findOrFail($communicationLog);
        $this->authorize('view', $log);

        $recipientLabel = null;

        if ($log->recipient_type && $log->recipient_id) {
            $recipientLabel = NotificationRecipients::labelFor(
                (string) $log->recipient_type,
                NotificationRecipients::resolve((string) $log->recipient_type, (int) $log->recipient_id, (int) $log->college_id),
            );
        }

        return view('communication.logs.show', [
            'log' => $log->load(['template:id,name,code', 'creator:id,name']),
            'recipientLabel' => $recipientLabel,
        ]);
    }
}
