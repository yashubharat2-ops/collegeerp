<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicYear\StoreAcademicYearRequest;
use App\Http\Requests\AcademicYear\UpdateAcademicYearRequest;
use App\Models\AcademicYear;
use App\Models\College;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcademicYearController extends Controller
{
    /** Snapshot of the fields worth showing in the audit trail. */
    private const AUDITED = ['id', 'name', 'code', 'starts_on', 'ends_on', 'status'];

    public function index(): View
    {
        $this->authorize('viewAny', AcademicYear::class);
        return view('academic-years.index', ['academicYears' => AcademicYear::latest('starts_on')->paginate(15)]);
    }

    public function store(StoreAcademicYearRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->require()->id;
        $academicYear = DB::transaction(function () use ($data, $collegeId): AcademicYear {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
            $overlap = AcademicYear::query()->where('college_id', $collegeId)->where(function ($query) use ($data) {
                $query->whereBetween('starts_on', [$data['starts_on'], $data['ends_on']])->orWhereBetween('ends_on', [$data['starts_on'], $data['ends_on']])->orWhere(function ($q) use ($data) { $q->where('starts_on', '<=', $data['starts_on'])->where('ends_on', '>=', $data['ends_on']); });
            })->exists();
            if ($overlap) throw ValidationException::withMessages(['starts_on' => 'Academic years cannot overlap for a college.']);
            if ($data['status'] === 'active') AcademicYear::where('college_id', $collegeId)->where('status', 'active')->update(['status' => 'inactive']);
            return AcademicYear::create($data + ['college_id' => $collegeId, 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
        });
        $audit->record('academic_year.created', $academicYear, [], $academicYear->only(['id', 'name', 'code', 'status', 'starts_on', 'ends_on']));
        return back()->with('success', 'Academic year created.');
    }

    public function edit(string $academic_year): View
    {
        $model = $this->findScoped($academic_year);
        $this->authorize('update', $model);

        return view('academic-years.edit', ['academicYear' => $model]);
    }

    /**
     * Update an academic year, including its status.
     *
     * This is the workflow that was always intended but never built: the policy
     * already declared update() and `academic-years.update` was already seeded,
     * yet no route or controller method existed — so a year's status could only
     * ever be set once, at creation. A college whose active year was later
     * superseded had no way to reactivate it, and a year created with the
     * column's `inactive` default could never become active at all.
     *
     * The transaction shape mirrors store() deliberately, so the two write
     * paths cannot drift apart:
     *
     * 1. take the College row lock, which serialises the single-active-year
     *    rule against a concurrent create or update;
     * 2. re-check the no-overlap rule under that lock, excluding this year. The
     *    Form Request cannot see a row another request commits between its own
     *    validation and this write, so the check is repeated here;
     * 3. if this year becomes active, deactivate every OTHER active year — the
     *    same invariant store() maintains;
     * 4. write. The model's `saving` hook still enforces ends_on > starts_on.
     *
     * Status transitions are unrestricted between the two existing values, and
     * deactivating the last active year is allowed. That is not a new rule: a
     * college with zero active years is already a legal state — `inactive` is
     * the column default, and both readers of the active year
     * (AcademicConfigurationController and InstitutionalSettingController)
     * already render an explicit "no active session" fallback. Nothing outside
     * those two display badges branches on this column, so no enrollment,
     * promotion or historical record is invalidated by a transition here.
     */
    public function update(UpdateAcademicYearRequest $request, string $academic_year, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($academic_year);
        $this->authorize('update', $model);

        $data = $request->validated();
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->require()->id;
        $old = $model->only(self::AUDITED);

        DB::transaction(function () use ($model, $data, $collegeId): void {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $overlap = AcademicYear::query()
                ->where('college_id', $collegeId)
                ->whereKeyNot($model->id)
                ->where(function ($query) use ($data): void {
                    $query->whereBetween('starts_on', [$data['starts_on'], $data['ends_on']])
                        ->orWhereBetween('ends_on', [$data['starts_on'], $data['ends_on']])
                        ->orWhere(function ($q) use ($data): void {
                            $q->where('starts_on', '<=', $data['starts_on'])->where('ends_on', '>=', $data['ends_on']);
                        });
                })
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'Academic years cannot overlap for a college.']);
            }

            if ($data['status'] === 'active') {
                AcademicYear::query()
                    ->where('college_id', $collegeId)
                    ->where('status', 'active')
                    ->whereKeyNot($model->id)
                    ->update(['status' => 'inactive']);
            }

            $model->update($data + ['updated_by' => auth()->id()]);
        });

        $audit->record('academic_year.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('academic-years.index')->with('success', 'Academic year updated.');
    }

    private function findScoped(string $id): AcademicYear
    {
        return AcademicYear::query()->findOrFail($id);
    }
}
