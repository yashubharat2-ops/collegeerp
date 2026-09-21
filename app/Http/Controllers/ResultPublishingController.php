<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResultPublishing\PublishResultRequest;
use App\Models\ExamResult;
use App\Models\Examination;
use App\Services\Examinations\ResultPublishingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Result Publishing (Examinations Phase 3) — separate from calculation.
 *
 * Lifecycle: Draft / Calculated → Ready for Publishing → Published.
 *
 * Only calculated results with a usable grading configuration can be
 * published; draft, incomplete and failed results are blocked. Publishing is
 * transactional, records published_at + published_by, and is the only place a
 * result may become visible externally. Unpublishing requires its own explicit
 * permission.
 *
 * Tenant isolation: ExamResult carries CollegeScope and every supplied id is
 * re-checked against the active college and the requested examination.
 */
class ResultPublishingController extends Controller
{
    public function __construct(private readonly ResultPublishingService $publisher)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewPublishing', ExamResult::class);

        $examinationId = $request->input('examination_id');

        $results = ExamResult::query()
            ->with([
                'examination',
                'studentEnrollment.student',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'gradeScale',
                'publishedBy',
            ])
            ->when($examinationId !== null, fn ($q) => $q->where('examination_id', (int) $examinationId))
            // Unpublished first: that is what an operator acts on.
            ->orderByRaw('CASE WHEN published_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('result_publishing.index', [
            'results' => $results,
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']),
            'filters' => [
                'examination_id' => $examinationId,
                'calculation_status' => $request->input('calculation_status'),
            ],
            'canUnpublish' => $request->user()?->can('unpublish', ExamResult::class) ?? false,
            'calculationStatuses' => ExamResult::CALCULATION_STATUSES,
        ]);
    }

    public function publish(Request $request, string $result): RedirectResponse
    {
        $model = $this->findScoped($result);

        $this->authorize('publish', $model);

        $this->publisher->publish($model, $request->user());

        return redirect()
            ->back()
            ->with('success', 'Result published.');
    }

    public function unpublish(Request $request, string $result): RedirectResponse
    {
        $model = $this->findScoped($result);

        $this->authorize('unpublish', $model);

        $this->publisher->unpublish($model, $request->user());

        return redirect()
            ->back()
            ->with('success', 'Result unpublished.');
    }

    public function publishBulk(PublishResultRequest $request): RedirectResponse
    {
        $results = $this->resolveResults($request);

        $summary = DB::transaction(fn () => $this->publisher->publishMany($results, $request->user()));

        $message = "Published {$summary['published']} result(s).";

        if ($summary['skipped'] > 0) {
            $message .= " {$summary['skipped']} result(s) were not eligible and were skipped.";
        }

        return redirect()->back()->with('success', $message);
    }

    public function publishExamination(Request $request, string $examination): RedirectResponse
    {
        $model = Examination::query()->findOrFail($examination);

        $this->authorize('publish', ExamResult::class);

        $summary = app(ResultPublishingService::class)->publishExamination($model, $request->user());

        return redirect()
            ->back()
            ->with('success', "Published {$summary['published']} result(s) for \"{$model->name}\".");
    }

    /**
     * Resolve an ExamResult inside the controller, under the active tenant.
     *
     * Implicit route model binding is deliberately not used for tenant-scoped
     * models: SubstituteBindings belongs to the `web` middleware group and runs
     * before the `tenant` middleware, so a binding resolved there would see no
     * tenant context.
     */
    private function findScoped(string $id): ExamResult
    {
        return ExamResult::query()->findOrFail($id);
    }

    /**
     * Resolve the requested result ids inside the active college and — when an
     * examination scope is supplied — inside that examination only.
     */
    private function resolveResults(PublishResultRequest $request): \Illuminate\Database\Eloquent\Collection
    {
        $ids = collect((array) ($request->input('result_ids') ?: [$request->input('result_id')]))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'result_ids' => 'Select at least one result to publish.',
            ]);
        }

        $query = ExamResult::query()->whereIn('id', $ids->all());

        // Context validation: ExamResult → Examination (same tenant, same exam).
        if (($examinationId = $request->examinationId()) !== null) {
            $query->where('examination_id', $examinationId);
        }

        $results = $query->orderBy('id')->get();

        if ($results->isEmpty()) {
            throw ValidationException::withMessages([
                'result_ids' => 'The selected results are not available for publishing.',
            ]);
        }

        return $results;
    }
}
