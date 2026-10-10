<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdmissionApplicant\StoreAdmissionApplicantRequest;
use App\Http\Requests\AdmissionApplicant\UpdateAdmissionApplicantRequest;
use App\Models\AdmissionApplicant;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionApplicantController extends Controller
{
    private const AUDITED = ['id', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'alternate_phone', 'gender', 'date_of_birth', 'address', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionApplicant::class);

        $query = AdmissionApplicant::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id');

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

    /**
     * CSV export of the selected applicants (or all visible ones when no selection).
     *
     * Identity and sensitive columns are never exported: the column list is
     * explicit (no address, no document data, no secrets), and the audit entry
     * records counts only.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', AdmissionApplicant::class);

        $query = AdmissionApplicant::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('admission_applicants.id', $ids);
        }

        $audit->record('admission_applicants.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('admission-applicants-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'First name', 'Middle name', 'Last name', 'Email', 'Phone', 'Alternate phone',
                'Gender', 'Date of birth', 'Status',
            ])
            ->map(function (AdmissionApplicant $applicant): array {
                return CsvStreamExport::safeRow([
                    $applicant->first_name,
                    $applicant->middle_name,
                    $applicant->last_name,
                    $applicant->email,
                    $applicant->phone,
                    $applicant->alternate_phone,
                    $applicant->gender,
                    $applicant->date_of_birth?->format('Y-m-d'),
                    $applicant->status,
                ]);
            })
            ->streamFromQuery($query);
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

        // Refuse while live records still point at this applicant. Soft-deleting
        // it would leave those records without their applicant (their list pages
        // would then show a blank name or fail), and their history must stay intact.
        $blocker = $model->deletionBlocker();
        if ($blocker !== null) {
            return back()->withErrors(['applicant' => $blocker]);
        }

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
