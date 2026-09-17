<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdmissionMeritEntry\StoreAdmissionMeritEntryRequest;
use App\Http\Requests\AdmissionMeritEntry\UpdateAdmissionMeritEntryRequest;
use App\Models\AdmissionApplication;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionMeritEntryController extends Controller
{
    private const AUDITED = ['id','merit_list_id','application_id','applicant_id','merit_score','rank','selection_status','remarks'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionMeritEntry::class);

        $query = AdmissionMeritEntry::query()
            ->with(['meritList','application.applicant','applicant'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($meritListId = $request->input('merit_list_id')) {
            $query->where('merit_list_id', $meritListId);
        }

        if (in_array($request->input('selection_status'), AdmissionMeritEntry::SELECTION_STATUSES, true)) {
            $query->where('selection_status', $request->input('selection_status'));
        }

        return view('admission_merit_entries.index', [
            'entries' => $query->paginate(15)->withQueryString(),
            'merit_list_id' => $request->input('merit_list_id'),
            'selection_status' => $request->input('selection_status'),
            'meritLists' => $this->meritListOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', AdmissionMeritEntry::class);

        $meritListId = $request->input('merit_list_id');

        return view('admission_merit_entries.create', [
            'meritLists' => $this->meritListOptions(),
            'applications' => $this->applicationOptions(),
            'selectedMeritListId' => $meritListId,
        ]);
    }

    public function store(StoreAdmissionMeritEntryRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        // Tenant-scoped re-resolution: ensure application belongs to active college
        $application = AdmissionApplication::query()->findOrFail($data['application_id']);
        $meritList = AdmissionMeritList::query()->findOrFail($data['merit_list_id']);

        // Prevent cross-college mixing (scope already ensures, but double-check college_id match)
        if ((int) $application->college_id !== (int) $meritList->college_id) {
            abort(404);
        }

        // If merit list is scoped to program/year, optionally validate application matches
        if ($meritList->program_id && $application->program_id && (int) $meritList->program_id !== (int) $application->program_id) {
            return back()->withErrors(['application_id' => 'Application program does not match merit list program.'])->withInput();
        }

        if ($meritList->academic_year_id && $application->academic_year_id && (int) $meritList->academic_year_id !== (int) $application->academic_year_id) {
            return back()->withErrors(['application_id' => 'Application academic year does not match merit list academic year.'])->withInput();
        }

        $data['applicant_id'] = $application->applicant_id;
        $data['college_id'] = $meritList->college_id;

        // Prevent duplicate entry for same application in same list (DB unique also)
        $exists = AdmissionMeritEntry::withoutGlobalScopes()
            ->where('merit_list_id', $data['merit_list_id'])
            ->where('application_id', $data['application_id'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['application_id' => 'This application already exists in the selected merit list.'])->withInput();
        }

        $entry = AdmissionMeritEntry::create($data);

        $audit->record('admission_merit_entry.created', $entry, [], $entry->only(self::AUDITED));

        return redirect()->route('admission-merit-lists.show', $meritList)->with('success', 'Merit entry added.');
    }

    public function edit(string $admission_merit_entry): View
    {
        $model = $this->findScoped($admission_merit_entry);
        $this->authorize('update', $model);

        return view('admission_merit_entries.edit', [
            'entry' => $model->load(['meritList','application.applicant']),
        ]);
    }

    public function update(UpdateAdmissionMeritEntryRequest $request, string $admission_merit_entry, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_entry);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated());

        $audit->record('admission_merit_entry.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Merit entry updated.');
    }

    public function destroy(string $admission_merit_entry, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_merit_entry);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_merit_entry.deleted', $model, $snapshot, []);

        return back()->with('success', 'Merit entry deleted.');
    }

    private function findScoped(string $id): AdmissionMeritEntry
    {
        return AdmissionMeritEntry::query()->findOrFail($id);
    }

    private function meritListOptions()
    {
        return AdmissionMeritList::query()->orderByDesc('created_at')->get(['id','name','code','academic_year_id','program_id']);
    }

    private function applicationOptions()
    {
        return AdmissionApplication::query()
            ->with('applicant:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id','application_number','applicant_id','program_id','academic_year_id','status']);
    }
}
