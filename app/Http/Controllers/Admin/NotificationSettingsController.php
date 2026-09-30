<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Communication\Services\CommunicationTemplateService;
use App\Domain\Communication\Support\CommunicationChannels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTemplateStatusRequest;
use App\Models\CommunicationTemplate;
use App\Models\InstitutionalSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewNotifications', InstitutionalSetting::class);
        $filters = $request->validate(['channel' => ['nullable', 'in:'.implode(',', CommunicationChannels::all())], 'status' => ['nullable', 'in:'.implode(',', CommunicationTemplate::STATUSES)]]);
        $canTemplates = Gate::allows('viewAny', CommunicationTemplate::class);
        $templates = null;
        if ($canTemplates) {
            $query = CommunicationTemplate::query()->select(['id', 'name', 'code', 'channel', 'status', 'college_id'])->orderBy('channel')->orderBy('name');
            foreach (['channel', 'status'] as $field) {
                if (! empty($filters[$field])) {
                    $query->where($field, $filters[$field]);
                }
            }
            $templates = $query->paginate(15)->withQueryString();
        }

        return view('administration.notification-settings.index', [
            'canTemplates' => $canTemplates, 'templates' => $templates, 'filters' => $filters,
            'channels' => CommunicationChannels::LABELS,
            'limits' => [
                'page_size' => (int) config('communication.per_page', 15),
                'recipient_options' => (int) config('communication.recipient_option_limit', 500),
                'attachment_kb' => (int) config('communication.attachments.max_kb', 5120),
            ],
        ]);
    }

    public function updateStatus(UpdateTemplateStatusRequest $request, string $template, CommunicationTemplateService $templates): RedirectResponse
    {
        // Delegates to the existing service, preserving its validation, tenant
        // locking and audit event. Activation never sends a message.
        $templates->update($request->template(), $request->validated(), $request->user());

        return back()->with('success', 'Template availability updated. No message was sent.');
    }
}
