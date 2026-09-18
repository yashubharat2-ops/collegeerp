<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdmissionMeritList\StoreAdmissionMeritListRequest;
use App\Http\Requests\AdmissionMeritList\UpdateAdmissionMeritListRequest;
use App\Models\AcademicYear;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionMeritListController extends Controller
{
    private const AUDITED = ['id','code','name','academic_year_id','program_id','status','is_published','description','remarks'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionMeritList::class);

        // Deterministic creation-order pagination (oldest first) with id tiebreak,
        // so rows never shuffle between pages under equal-second timestamps.
        $query = AdmissionMeritList::query()
            ->with(['academicYear','program'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->input('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->input('program_id')) {
            $query->where('program_id', $request->input('program_id'));
        }

        if (in_array($request->input('status'), ['draft','published'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('admission_merit_lists.index', [
            'meritLists' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'status' => $request->input('status'),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AdmissionMeritList::class);

        return view('admission_merit_lists.create', [
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function store(StoreAdmissionMeritListRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['status'] = 'draft';
        $data['is_published'] = false;

        $list = AdmissionMeritList::create($data);

        $audit->record('admission_merit_list.created', $list, [], $list->only(self::AUDITED));

        return redirect()->route('admission-merit-lists.index')->with('success', 'Merit list '.$list->code.' created.');
    }

    public function show(string $admission_merit_list): View
    {
        $model = $this->findScoped($admission_merit_list);
        $this->authorize('view', $model);

        $entries = AdmissionMeritEntry::query()
            ->where('merit_list_id', $model->id)
            ->with(['application.applicant','applicant'])
            ->orderBy('rank')
            ->orderByDesc('merit_score')
            ->orderBy('id')
            ->paginate(25);

        return view('admission_merit_lists.show', [
            'meritList' => $model->load(['academicYear','program']),
            'entries' => $entries,
        ]);
    }

    public function edit(string $admission_merit_list): View
    {
        $model = $this->findScoped($admission_merit_list);
        $this->authorize('update', $model);

        return view('admission_merit_lists.edit', [
            'meritList' => $model,
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function update(UpdateAdmissionMeritListRequest $request, string $admission_merit_list, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_list);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated());

        $audit->record('admission_merit_list.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Merit list updated.');
    }

    public function destroy(string $admission_merit_list, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_list);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_merit_list.deleted', $model, $snapshot, []);

        return back()->with('success', 'Merit list deleted.');
    }

    public function publish(string $admission_merit_list, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_list);
        $this->authorize('publish', $model);

        if ($model->is_published) {
            return back()->with('success', 'Merit list already published.');
        }

        $old = $model->only(self::AUDITED);

        $model->update([
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        $audit->record('admission_merit_list.published', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Merit list published.');
    }

    public function unpublish(string $admission_merit_list, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_list);
        $this->authorize('publish', $model);

        $old = $model->only(self::AUDITED);

        $model->update([
            'status' => 'draft',
            'is_published' => false,
            'published_at' => null,
            'published_by' => null,
        ]);

        $audit->record('admission_merit_list.unpublished', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Merit list unpublished.');
    }

    private function findScoped(string $id): AdmissionMeritList
    {
        return AdmissionMeritList::query()->findOrFail($id);
    }

    private function academicYearOptions()
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id','name','code']);
    }

    private function programOptions()
    {
        return Program::query()->orderBy('name')->get(['id','name','code']);
    }
}
