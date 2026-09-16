<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdmissionApplicant\StoreAdmissionApplicantRequest;
use App\Http\Requests\AdmissionApplicant\UpdateAdmissionApplicantRequest;
use App\Models\AdmissionApplicant;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionApplicantController extends Controller
{
    private const AUDITED = ['id', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'alternate_phone', 'gender', 'date_of_birth', 'address', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionApplicant::class);

        $query = AdmissionApplicant::query()->orderBy('first_name')->orderBy('last_name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('admission_applicants.index', [
            'applicants' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AdmissionApplicant::class);

        return view('admission_applicants.create');
    }

    public function store(StoreAdmissionApplicantRequest $request, AuditLogService $audit): RedirectResponse
    {
        $applicant = AdmissionApplicant::create($request->validated());
        $audit->record('admission_applicant.created', $applicant, [], $applicant->only(self::AUDITED));

        return redirect()->route('admission-applicants.index')->with('success', 'Applicant created.');
    }

    public function edit(string $admission_applicant): View
    {
        $model = $this->findScoped($admission_applicant);
        $this->authorize('update', $model);

        return view('admission_applicants.edit', [
            'applicant' => $model,
        ]);
    }

    public function update(UpdateAdmissionApplicantRequest $request, string $admission_applicant, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_applicant);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated());
        $audit->record('admission_applicant.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Applicant updated.');
    }

    public function destroy(string $admission_applicant, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_applicant);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_applicant.deleted', $model, $snapshot, []);

        return back()->with('success', 'Applicant deleted.');
    }

    /**
     * Tenant-safe lookup: scoped query ensures cross-college 404, not 403 leak.
     */
    private function findScoped(string $id): AdmissionApplicant
    {
        return AdmissionApplicant::query()->findOrFail($id);
    }
}
