<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CommunicationTemplateService;
use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreCommunicationTemplateRequest;
use App\Http\Requests\Communication\UpdateCommunicationTemplateRequest;
use App\Models\CommunicationTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SMS / e-mail templates (Communication Management, Phase 2).
 *
 * Reusable definitions only: creating or editing a template sends nothing and
 * contacts no external provider. Thin controller — validation in the Form
 * Requests, rules in CommunicationTemplateService, college_id from the tenant
 * context, records resolved under the active tenant (a foreign id is a 404).
 */
class CommunicationTemplateController extends Controller
{
    public function __construct(private readonly CommunicationTemplateService $templates) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CommunicationTemplate::class);

        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'channel' => CommunicationFilters::choice($request->query('channel'), CommunicationChannels::all()),
            'status' => CommunicationFilters::choice($request->query('status'), CommunicationTemplate::STATUSES),
        ];

        $query = CommunicationTemplate::query()
            ->with('creator:id,name')
            ->orderBy('name')
            ->orderBy('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        foreach (['channel', 'status'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        return view('communication.templates.index', [
            'templates' => $query->paginate((int) config('communication.per_page', 15))->withQueryString(),
            'filters' => $filters,
            'channels' => CommunicationChannels::LABELS,
            'statuses' => CommunicationTemplate::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', CommunicationTemplate::class);

        return view('communication.templates.create', [
            'template' => new CommunicationTemplate,
            'channels' => CommunicationChannels::LABELS,
            'statuses' => CommunicationTemplate::STATUSES,
        ]);
    }

    public function store(StoreCommunicationTemplateRequest $request): RedirectResponse
    {
        $template = $this->templates->create(
            app(TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('communication-templates.show', $template)
            ->with('success', 'Template created.');
    }

    public function show(string $communicationTemplate): View
    {
        $template = $this->findScoped($communicationTemplate);
        $this->authorize('view', $template);

        return view('communication.templates.show', [
            'template' => $template->load(['creator:id,name', 'updater:id,name']),
        ]);
    }

    public function edit(string $communicationTemplate): View
    {
        $template = $this->findScoped($communicationTemplate);
        $this->authorize('update', $template);

        return view('communication.templates.edit', [
            'template' => $template,
            'channels' => CommunicationChannels::LABELS,
            'statuses' => CommunicationTemplate::STATUSES,
        ]);
    }

    public function update(UpdateCommunicationTemplateRequest $request, string $communicationTemplate): RedirectResponse
    {
        $template = $this->findScoped($communicationTemplate);

        $template = $this->templates->update($template, $request->validated(), $request->user());

        return redirect()
            ->route('communication-templates.show', $template)
            ->with('success', 'Template updated.');
    }

    public function destroy(Request $request, string $communicationTemplate): RedirectResponse
    {
        $template = $this->findScoped($communicationTemplate);
        $this->authorize('delete', $template);

        $this->templates->delete($template, $request->user());

        return redirect()
            ->route('communication-templates.index')
            ->with('success', 'Template deleted.');
    }

    /** Explicit tenant-scoped resolution: another college's id 404s. */
    private function findScoped(string $id): CommunicationTemplate
    {
        return CommunicationTemplate::query()->findOrFail($id);
    }
}
