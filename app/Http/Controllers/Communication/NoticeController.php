<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationAttachmentService;
use App\Domain\Communication\Services\NoticeService;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreNoticeRequest;
use App\Http\Requests\Communication\UpdateNoticeRequest;
use App\Models\Notice;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notices / Announcements (Communication Management, Phase 1).
 *
 * Thin controller: validation lives in the Form Requests, the workflow /
 * tenant / attachment rules in NoticeService, and college_id always comes from
 * the tenant context. Records are resolved INSIDE the controller under the
 * active tenant (the project never relies on implicit route model binding
 * for tenant-scoped models), so a foreign college's id is a 404.
 */
class NoticeController extends Controller
{
    public function __construct(private readonly NoticeService $notices) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Notice::class);

        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'status' => CommunicationFilters::choice($request->query('status'), Notice::STATUSES),
            'priority' => CommunicationFilters::choice($request->query('priority'), Notice::PRIORITIES),
            'notice_type' => CommunicationFilters::type($request->query('notice_type')),
            'target_type' => CommunicationFilters::choice($request->query('target_type'), array_keys(CommunicationTargets::forNotices())),
            'date_from' => CommunicationFilters::date($request->query('date_from')),
            'date_to' => CommunicationFilters::date($request->query('date_to')),
        ];

        $query = Notice::query()
            ->with('creator:id,name')
            // Deterministic pagination: newest publication first, id breaks ties.
            ->orderByDesc('publish_at')
            ->orderByDesc('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'priority', 'notice_type', 'target_type'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['date_from']) {
            $query->where('publish_at', '>=', $filters['date_from']->copy()->startOfDay());
        }

        if ($filters['date_to']) {
            $query->where('publish_at', '<=', $filters['date_to']->copy()->endOfDay());
        }

        return view('communication.notices.index', [
            'notices' => $query->paginate((int) config('communication.per_page', 15))->withQueryString(),
            'filters' => $filters,
            'statuses' => Notice::STATUSES,
            'priorities' => Notice::PRIORITIES,
            'types' => Notice::TYPES,
            'targets' => CommunicationTargets::forNotices(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Notice::class);

        return view('communication.notices.create', $this->formData(new Notice([
            'publish_at' => now()->startOfMinute(),
            'notice_type' => 'general',
        ])));
    }

    public function store(StoreNoticeRequest $request): RedirectResponse
    {
        $notice = $this->notices->create(
            app(TenantContext::class)->require(),
            $request->safe()->except(['attachment']),
            $request->user(),
            $request->file('attachment'),
        );

        return redirect()
            ->route('notices.show', $notice)
            ->with('success', "Notice \"{$notice->title}\" saved as a draft.");
    }

    public function show(string $notice): View
    {
        $model = $this->findScoped($notice);
        $this->authorize('view', $model);

        return view('communication.notices.show', [
            'notice' => $model->load(['creator:id,name', 'updater:id,name']),
        ]);
    }

    public function edit(string $notice): View|RedirectResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('update', $model);

        if ($model->isArchived()) {
            return redirect()
                ->route('notices.show', $model)
                ->withErrors(['notice' => 'Archived notices are read-only. Unpublish it back to draft before editing.']);
        }

        return view('communication.notices.edit', $this->formData($model));
    }

    public function update(UpdateNoticeRequest $request, string $notice): RedirectResponse
    {
        $model = $this->findScoped($notice);

        $model = $this->notices->update(
            $model,
            $request->safe()->except(['attachment', 'remove_attachment']),
            $request->user(),
            $request->file('attachment'),
            $request->boolean('remove_attachment'),
        );

        return redirect()
            ->route('notices.show', $model)
            ->with('success', "Notice \"{$model->title}\" updated.");
    }

    public function destroy(Request $request, string $notice): RedirectResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('delete', $model);

        $title = $model->title;
        $this->notices->delete($model, $request->user());

        return redirect()
            ->route('notices.index')
            ->with('success', "Notice \"{$title}\" deleted.");
    }

    public function publish(Request $request, string $notice): RedirectResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('publish', $model);

        $model = $this->notices->publish($model, $request->user());

        return redirect()->route('notices.show', $model)->with('success', "Notice \"{$model->title}\" published.");
    }

    public function unpublish(Request $request, string $notice): RedirectResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('publish', $model);

        $model = $this->notices->unpublish($model, $request->user());

        return redirect()->route('notices.show', $model)->with('success', "Notice \"{$model->title}\" moved back to draft.");
    }

    public function archive(Request $request, string $notice): RedirectResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('publish', $model);

        $model = $this->notices->archive($model, $request->user());

        return redirect()->route('notices.show', $model)->with('success', "Notice \"{$model->title}\" archived.");
    }

    /**
     * Stream the private attachment: authorization first, then the stored key
     * is re-validated against the notice's own college directory, then the
     * download is audited. Only the record id comes from the URL — never a
     * path or filename.
     */
    public function attachment(string $notice, CommunicationAttachmentService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($notice);
        $this->authorize('download', $model);

        abort_unless($model->hasAttachment(), 404);

        $response = $files->download(
            $model->attachment_path,
            CommunicationAttachmentService::AREA_NOTICES,
            (int) $model->college_id,
            $model->attachment_name,
            'notice-'.$model->getKey(),
        );

        $audit->record('notices.attachment_downloaded', $model, [], [
            'attachment_name' => $model->attachment_name,
            'attachment_path' => $model->attachment_path,
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Notice $notice): array
    {
        return [
            'notice' => $notice,
            'types' => Notice::TYPES,
            'priorities' => Notice::PRIORITIES,
            'targets' => CommunicationTargets::forNotices(),
            'entityTargets' => CommunicationTargets::ENTITIES,
            'entityOptions' => CommunicationTargets::entityOptions(),
            'maxKb' => CommunicationAttachmentService::maxKilobytes(),
            'extensions' => CommunicationAttachmentService::allowedExtensions(),
        ];
    }

    /** Explicit tenant-scoped resolution: a foreign or archived id 404s. */
    private function findScoped(string $id): Notice
    {
        return Notice::query()->findOrFail($id);
    }
}
