<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Services\AdmissionService;
use App\Http\Requests\Admission\StoreAdmissionRequest;
use App\Http\Requests\Admission\UpdateAdmissionRequest;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplication;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionController extends Controller
{
    private const AUDITED = ['id','admission_number','application_id','applicant_id','academic_year_id','program_id','admission_date','status','remarks'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Admission::class);

        // Deterministic creation-order pagination (oldest first) with id tiebreak,
        // so rows never shuffle between pages under equal-second timestamps.
        // (admission_date is not a stable global sort: many records share a date,
        // which caused page-membership to flip under newest-first ordering.)
        $query = Admission::query()
            ->with(['applicant','application','academicYear','program'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('admission_number', 'like', "%{$search}%")
                  ->orWhereHas('applicant', function ($aq) use ($search): void {
                      $aq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('application', function ($appQ) use ($search): void {
                      $appQ->where('application_number', 'like', "%{$search}%");
                  });
            });
        }

        if (in_array($request->input('status'), Admission::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->input('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->input('program_id')) {
            $query->where('program_id', $request->input('program_id'));
        }

        return view('admissions.index', [
            'admissions' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Admission::class);

        return view('admissions.create', [
            'applications' => $this->applicationOptions(),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'selectedApplicationId' => $request->input('application_id'),
        ]);
    }

    public function store(StoreAdmissionRequest $request, AdmissionService $service): RedirectResponse
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        $admission = $service->createFromApplication($request->validated(), $collegeId);

        return redirect()->route('admissions.index')->with('success', 'Admission '.$admission->admission_number.' created.');
    }

    public function edit(string $admission): View
    {
        $model = $this->findScoped($admission);
        $this->authorize('update', $model);

        return view('admissions.edit', [
            'admission' => $model->load(['applicant','application','academicYear','program']),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function update(UpdateAdmissionRequest $request, string $admission, AdmissionService $service): RedirectResponse
    {
        $model = $this->findScoped($admission);

        $service->updateAdmission($model, $request->validated());

        return back()->with('success', 'Admission updated.');
    }

    public function destroy(string $admission, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission);
        $this->authorize('delete', $model);

        // For final admission, we soft delete but also allow cancellation via status.
        // Here destroy is hard-ish soft delete for admin correction.
        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission.deleted', $model, $snapshot, []);

        return back()->with('success', 'Admission deleted.');
    }

    public function cancel(Request $request, string $admission, AdmissionService $service): RedirectResponse
    {
        $model = $this->findScoped($admission);
        $this->authorize('update', $model);

        $request->validate([
            'remarks' => ['nullable','string','max:2000'],
        ]);

        $service->cancelAdmission($model, $request->input('remarks'));

        return back()->with('success', 'Admission cancelled.');
    }

    private function findScoped(string $id): Admission
    {
        return Admission::query()->findOrFail($id);
    }

    private function academicYearOptions()
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id','name','code']);
    }

    private function programOptions()
    {
        return Program::query()->orderBy('name')->get(['id','name','code']);
    }

    private function applicationOptions()
    {
        return AdmissionApplication::query()
            ->with('applicant:id,first_name,last_name')
            ->whereIn('status', ['approved','submitted','under_review','admitted'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id','application_number','applicant_id','status','program_id','academic_year_id']);
    }
}
