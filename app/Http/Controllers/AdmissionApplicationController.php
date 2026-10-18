<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Actions\CreateApplication;
use App\Domain\Admission\Services\AdmissionApplicationStatusService;
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
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * CSV export of the selected applications (or all visible ones when no selection).
     * Tenant-scoped; read-only; counts-only audit entry.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', AdmissionApplication::class);

        $query = AdmissionApplication::query()->with(['applicant', 'academicYear', 'program'])->orderBy('id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('admission_applications.id', $ids);
        }

        $audit->record('admission_applications.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('admission-applications-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Application number', 'Applicant', 'Email', 'Phone', 'Academic year', 'Program',
                'Status', 'Submitted at', 'Remarks',
            ])
            ->map(function (AdmissionApplication $application): array {
                return CsvStreamExport::safeRow([
                    $application->application_number,
                    trim(($application->applicant?->first_name ?? '').' '.($application->applicant?->last_name ?? '')),
                    $application->applicant?->email,
                    $application->applicant?->phone,
                    $application->academicYear?->name,
                    $application->program?->name,
                    $application->status,
                    $application->submitted_at?->format('Y-m-d H:i'),
                    $application->remarks,
                ]);
            })
            ->streamFromQuery($query);
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

    public function store(StoreAdmissionApplicationRequest $request, CreateApplication $action, \App\Services\Approval\ApprovalService $approvalService): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();
        $user = $request->user();
        $data = $request->validated();

        // Check if approval is required for this submission
        $needsApproval = $approvalService->isApprovalRequired($collegeId, 'admission', 'application_submit');
        $canApprove = $approvalService->canApprove($user, 'admission', 'application_submit', $collegeId);

        // Draft never requires approval - it's a private working copy
        if (($data['status'] ?? 'draft') === 'draft' || ! $needsApproval || $canApprove) {
            $application = $action->execute($data, $collegeId);
            return redirect()->route('admission-applications.index')->with('success', 'Application '.$application->application_number.' created.');
        }

        // Non-approver creating a non-draft application: create pending and request approval
        $application = $action->execute($data, $collegeId);

        $approvalService->submitRequest([
            'module' => 'admission',
            'action' => 'application_submit',
            'target_type' => 'admission.application_submit',
            'target_id' => $application->id,
            'title' => 'Approval for application '.$application->application_number,
            'description' => 'New admission application submitted by '.$user->name,
            'payload' => ['status' => $data['status']],
            'request_reason' => $data['remarks'] ?? null,
        ], $collegeId, $user->getKey());

        return redirect()->route('admission-applications.index')->with('success', 'Application '.$application->application_number.' created and pending approval.');
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

    public function update(UpdateAdmissionApplicationRequest $request, string $admission_application, AuditLogService $audit, AdmissionApplicationStatusService $statuses, \App\Services\Approval\ApprovalService $approvalService): RedirectResponse
    {
        $model = $this->findScoped($admission_application);
        $old = $model->only(self::AUDITED);

        $data = $request->validated();

        // Workflow transitions and the server-side submitted_at stamp are owned by
        // AdmissionApplicationStatusService, shared with the bulk review action.
        $newStatus = $data['status'] ?? $model->status;
        if (! $statuses->canTransition($model->status, $newStatus)) {
            return back()->withErrors(['status' => 'Invalid status transition from '.$model->status.' to '.$newStatus.'. Allowed: '.implode(', ', $statuses->allowedFrom($model->status))])->withInput();
        }

        $data = $statuses->attributesForStatus($model, $newStatus, $data);

        $collegeId = app(TenantContext::class)->id();
        $user = $request->user();

        // If approval is required and user cannot approve, route through central approval instead of direct status change
        $isStatusChange = $newStatus !== $model->status;
        $needsApproval = $isStatusChange && $approvalService->isApprovalRequired($collegeId, 'admission', 'application_status_change');
        $canApprove = $approvalService->canApprove($user, 'admission', 'application_status_change', $collegeId);

        if ($needsApproval && ! $canApprove) {
            // Prevent duplicate pending approval for same target
            $existing = \App\Models\ApprovalRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('module', 'admission')
                ->where('action', 'application_status_change')
                ->where('target_type', 'admission.application_status_change')
                ->where('target_id', $model->id)
                ->where('status', \App\Models\ApprovalRequest::STATUS_PENDING)
                ->exists();
            if ($existing) {
                return back()->withErrors(['status' => 'A pending approval request already exists for this application.'])->withInput();
            }

            $approvalService->submitRequest([
                'module' => 'admission',
                'action' => 'application_status_change',
                'target_type' => 'admission.application_status_change',
                'target_id' => $model->id,
                'title' => 'Approval for status change: '.$model->application_number,
                'description' => 'Requested status change from '.$model->status.' to '.$newStatus,
                'payload' => $data,
                'previous_data' => $old,
                'request_reason' => $data['remarks'] ?? null,
            ], $collegeId, $user->getKey());

            return back()->with('success', 'Your status change request is pending approval.');
        }

        // Direct update for approvers or when approval not required
        // Also protect against self-approval via direct request: if needsApproval and canApprove but requester is same as previous? For update, the user is approver updating directly - allowed if they have permission
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
