<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Actions\CreateEnquiryWithApplicant;
use App\Domain\Admission\Services\DuplicateApplicantDetector;
use App\Http\Requests\AdmissionEnquiry\StoreAdmissionEnquiryRequest;
use App\Http\Requests\AdmissionEnquiry\UpdateAdmissionEnquiryRequest;
use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionEnquiryController extends Controller
{
    private const AUDITED = ['id', 'enquiry_number', 'applicant_id', 'academic_year_id', 'program_id', 'source', 'status', 'remarks', 'enquired_at', 'next_follow_up_at'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionEnquiry::class);

        $query = AdmissionEnquiry::query()->with(['applicant', 'academicYear', 'program'])->orderByDesc('created_at')->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('enquiry_number', 'like', "%{$search}%")
                  ->orWhereHas('applicant', function ($aq) use ($search): void {
                      $aq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        if (in_array($request->input('status'), ['new', 'contacted', 'followed_up', 'converted', 'closed', 'dropped'], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($programId = $request->input('program_id')) {
            $query->where('program_id', $programId);
        }

        return view('admission_enquiries.index', [
            'enquiries' => $query->paginate(15)->withQueryString(),
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
        $this->authorize('create', AdmissionEnquiry::class);

        return view('admission_enquiries.create', [
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'recentApplicants' => $this->recentApplicantOptions(),
        ]);
    }

    public function store(StoreAdmissionEnquiryRequest $request, CreateEnquiryWithApplicant $action): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();
        $enquiry = $action->execute($request->validated(), $collegeId);

        return redirect()->route('admission-enquiries.index')->with('success', 'Enquiry '.$enquiry->enquiry_number.' created.');
    }

    public function edit(string $admission_enquiry): View
    {
        $model = $this->findScoped($admission_enquiry);
        $this->authorize('update', $model);

        return view('admission_enquiries.edit', [
            'enquiry' => $model->load(['applicant', 'academicYear', 'program']),
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
        ]);
    }

    public function update(UpdateAdmissionEnquiryRequest $request, string $admission_enquiry, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_enquiry);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated());
        $audit->record('admission_enquiry.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Enquiry updated.');
    }

    public function destroy(string $admission_enquiry, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_enquiry);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_enquiry.deleted', $model, $snapshot, []);

        return back()->with('success', 'Enquiry deleted.');
    }

    /**
     * Duplicate check endpoint for UI: returns same-college applicants matching phone/email
     */
    public function duplicateCheck(Request $request, DuplicateApplicantDetector $detector): JsonResponse
    {
        $this->authorize('viewAny', AdmissionEnquiry::class);

        $collegeId = app(TenantContext::class)->id();
        $phone = $request->input('phone');
        $email = $request->input('email');

        $duplicates = $detector->detect($collegeId, $phone, $email, 5);

        return response()->json([
            'duplicates' => $duplicates->map(fn (AdmissionApplicant $a) => [
                'id' => $a->id,
                'name' => trim($a->first_name.' '.($a->middle_name ? $a->middle_name.' ' : '').$a->last_name),
                'email' => $a->email,
                'phone' => $a->phone,
            ]),
        ]);
    }

    private function findScoped(string $id): AdmissionEnquiry
    {
        return AdmissionEnquiry::query()->findOrFail($id);
    }

    private function academicYearOptions()
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']);
    }

    private function programOptions()
    {
        return Program::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function recentApplicantOptions()
    {
        return AdmissionApplicant::query()->orderByDesc('created_at')->limit(20)->get(['id', 'first_name', 'last_name', 'email', 'phone']);
    }
}
