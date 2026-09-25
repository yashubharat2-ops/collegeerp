<?php

namespace App\Http\Controllers\Communication;

use App\Domain\Communication\Services\CircularService;
use App\Domain\Communication\Services\CommunicationAttachmentService;
use App\Domain\Communication\Support\CommunicationFilters;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreCircularRequest;
use App\Http\Requests\Communication\UpdateCircularRequest;
use App\Models\Circular;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Circulars (Communication Management, Phase 1) — a separate module from
 * Notices with its own numbering, workflow permission and attachments.
 *
 * Thin controller: validation in the Form Requests, rules in CircularService,
 * college_id from the tenant context, records resolved under the active
 * tenant (a foreign id is a 404).
 */
class CircularController extends Controller
{
    public function __construct(private readonly CircularService $circulars) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Circular::class);

        $filters = [
            'search' => CommunicationFilters::text($request->query('search')),
            'status' => CommunicationFilters::choice($request->query('status'), Circular::STATUSES),
            'target_type' => CommunicationFilters::choice($request->query('target_type'), array_keys(CommunicationTargets::forCirculars())),
            'date_from' => CommunicationFilters::date($request->query('date_from')),
            'date_to' => CommunicationFilters::date($request->query('date_to')),
        ];

        $query = Circular::query()
            ->with('creator:id,name')
            // Deterministic pagination: latest issue date first, id breaks ties.
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('circular_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'target_type'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['date_from']) {
            $query->where('issue_date', '>=', $filters['date_from']->toDateString());
        }

        if ($filters['date_to']) {
            $query->where('issue_date', '<=', $filters['date_to']->toDateString());
        }

        return view('communication.circulars.index', [
            'circulars' => $query->paginate((int) config('communication.per_page', 15))->withQueryString(),
            'filters' => $filters,
            'statuses' => Circular::STATUSES,
            'targets' => CommunicationTargets::forCirculars(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Circular::class);

        return view('communication.circulars.create', $this->formData(new Circular([
            'issue_date' => now()->startOfDay(),
        ])));
    }

    public function store(StoreCircularRequest $request): RedirectResponse
    {
        $circular = $this->circulars->create(
            app(TenantContext::class)->require(),
            $request->safe()->except(['attachment']),
            $request->user(),
            $request->file('attachment'),
        );

        return redirect()
            ->route('circulars.show', $circular)
            ->with('success', "Circular {$circular->circular_number} saved as a draft.");
    }

    public function show(string $circular): View
    {
        $model = $this->findScoped($circular);
        $this->authorize('view', $model);

        return view('communication.circulars.show', [
            'circular' => $model->load(['creator:id,name', 'updater:id,name']),
        ]);
    }

    public function edit(string $circular): View|RedirectResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('update', $model);

        if ($model->isArchived()) {
            return redirect()
                ->route('circulars.show', $model)
                ->withErrors(['circular' => 'Archived circulars are read-only. Unpublish it back to draft before editing.']);
        }

        return view('communication.circulars.edit', $this->formData($model));
    }

    public function update(UpdateCircularRequest $request, string $circular): RedirectResponse
    {
        $model = $this->findScoped($circular);

        $model = $this->circulars->update(
            $model,
            $request->safe()->except(['attachment', 'remove_attachment']),
            $request->user(),
            $request->file('attachment'),
            $request->boolean('remove_attachment'),
        );

        return redirect()
            ->route('circulars.show', $model)
            ->with('success', "Circular {$model->circular_number} updated.");
    }

    public function destroy(Request $request, string $circular): RedirectResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('delete', $model);

        $number = $model->circular_number;
        $this->circulars->delete($model, $request->user());

        return redirect()
            ->route('circulars.index')
            ->with('success', "Circular {$number} deleted.");
    }

    public function publish(Request $request, string $circular): RedirectResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('publish', $model);

        $model = $this->circulars->publish($model, $request->user());

        return redirect()->route('circulars.show', $model)->with('success', "Circular {$model->circular_number} published.");
    }

    public function unpublish(Request $request, string $circular): RedirectResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('publish', $model);

        $model = $this->circulars->unpublish($model, $request->user());

        return redirect()->route('circulars.show', $model)->with('success', "Circular {$model->circular_number} moved back to draft.");
    }

    public function archive(Request $request, string $circular): RedirectResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('publish', $model);

        $model = $this->circulars->archive($model, $request->user());

        return redirect()->route('circulars.show', $model)->with('success', "Circular {$model->circular_number} archived.");
    }

    /**
     * Stream the private attachment (authorize → validate stored key → audit).
     */
    public function attachment(string $circular, CommunicationAttachmentService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($circular);
        $this->authorize('download', $model);

        abort_unless($model->hasAttachment(), 404);

        $response = $files->download(
            $model->attachment_path,
            CommunicationAttachmentService::AREA_CIRCULARS,
            (int) $model->college_id,
            $model->attachment_name,
            'circular-'.$model->getKey(),
        );

        $audit->record('circulars.attachment_downloaded', $model, [], [
            'attachment_name' => $model->attachment_name,
            'attachment_path' => $model->attachment_path,
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Circular $circular): array
    {
        return [
            'circular' => $circular,
            'targets' => CommunicationTargets::forCirculars(),
            'maxKb' => CommunicationAttachmentService::maxKilobytes(),
            'extensions' => CommunicationAttachmentService::allowedExtensions(),
        ];
    }

    /** Explicit tenant-scoped resolution: a foreign or archived id 404s. */
    private function findScoped(string $id): Circular
    {
        return Circular::query()->findOrFail($id);
    }
}
