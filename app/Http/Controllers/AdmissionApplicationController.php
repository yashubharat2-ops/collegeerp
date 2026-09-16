<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Actions\CreateApplication;
use App\Domain\Admission\Services\AdmissionApplicationWorkflow;
use App\Http\Requests\AdmissionApplication\StoreAdmissionApplicationRequest;
use App\Http\Requests\AdmissionApplication\UpdateAdmissionApplicationRequest;
use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionApplicationController extends Controller
{
    private const AUDITED = ['id', 'application_number', 'applicant_id', 'academic_year_id', 'program_id', 'enquiry_id', 'status', 'submitted_at', 'remarks'];

    private const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled', 'admitted'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionApplication::class);

        // Creation-order pagination (oldest first): created_at has only second
        // precision, and creation routinely spans several seconds, so a
        // newest-first primary inverts page membership whenever rows fall in
        // different seconds (an id tiebreak only stabilises rows within one
        // second). Oldest-first with the unique id tiebreak reproduces the
        // exact creation sequence under all timing conditions, so rows can
        // never shuffle between pages. This matches the ascending-listing
        // convention used by Departments, Programs and Applicants.
        $query = AdmissionApplication::query()
            ->with(['applicant', 'academicYear', 'program', 'enquiry'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('application_number', 'like', "%{$search}%")
                  ->orWhereHas('applicant', function ($aq) use ($search): void {
                      $aq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        if (in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($programId = $request->input('program_id')) {
            $query->where('program_id', $programId);
        }

        return view('admission_applications.index', [
            'applications' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AdmissionApplication::class);

        return view('admission_applications.create', [
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'applicants' => $this->applicantOptions(),
            'enquiries' => $this->enquiryOptions(),
        ]);
    }

    public function store(StoreAdmissionApplicationRequest $request, CreateApplication $action): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();
        $application = $action->execute($request->validated(), $collegeId);

        return redirect()->route('admission-applications.index')->with('success', 'Application '.$application->application_number.' created.');
    }

    public function edit(string $admission_application): View
    {
        $model = $this->findScoped($admission_application);
        $this->authorize('update', $model);

        $model->load(['applicant', 'academicYear', 'program', 'enquiry.applicant']);

        // Guarantee the currently linked enquiry is selectable even if it fell
        // outside the recent-enquiries window, so editing never drops the link.
        $enquiries = $this->enquiryOptions();
        if ($model->enquiry_id && ! $enquiries->contains('id', $model->enquiry_id)) {
            $current = AdmissionEnquiry::query()->with('applicant')->find($model->enquiry_id);
            if ($current) {
                $enquiries->prepend($current);
            }
        }

        return view('admission_applications.edit', [
            'application' => $model,
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'enquiries' => $enquiries,
        ]);
    }

    public function update(UpdateAdmissionApplicationRequest $request, string $admission_application, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_application);
        $old = $model->only(self::AUDITED);

        $data = $request->validated();

        // Enforce configurable workflow transitions
        $newStatus = $data['status'] ?? $model->status;
        if (! AdmissionApplicationWorkflow::canTransition($model->status, $newStatus)) {
            return back()->withErrors(['status' => 'Invalid status transition from '.$model->status.' to '.$newStatus.'. Allowed: '.implode(', ', AdmissionApplicationWorkflow::allowedFrom($model->status))])->withInput();
        }

        // Server-side submission stamping: the first transition out of draft
        // records when the application was submitted. The timestamp is never
        // accepted from the browser, and history is preserved (never cleared).
        if ($newStatus !== 'draft' && $model->submitted_at === null) {
            $data['submitted_at'] = now();
        }

        $model->update($data);
        $audit->record('admission_application.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Application updated.');
    }

    public function destroy(string $admission_application, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_application);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_application.deleted', $model, $snapshot, []);

        return back()->with('success', 'Application deleted.');
    }

    /**
     * Tenant-safe lookup: scoped query ensures cross-college 404, not 403 leak.
     */
    private function findScoped(string $id): AdmissionApplication
    {
        return AdmissionApplication::query()->findOrFail($id);
    }

    private function academicYearOptions()
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']);
    }

    private function programOptions()
    {
        return Program::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function applicantOptions()
    {
        return AdmissionApplicant::query()->orderByDesc('created_at')->limit(50)->get(['id', 'first_name', 'last_name', 'email', 'phone']);
    }

    private function enquiryOptions()
    {
        return AdmissionEnquiry::query()->with('applicant:id,first_name,last_name')->orderByDesc('created_at')->limit(50)->get(['id', 'enquiry_number', 'applicant_id', 'status']);
    }
}
