<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicYear\StoreAcademicYearRequest;
use App\Models\AcademicYear;
use App\Models\College;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcademicYearController extends Controller
{
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
}
