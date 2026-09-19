<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicTerm\StoreAcademicTermRequest;
use App\Http\Requests\AcademicTerm\UpdateAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AcademicTermController extends Controller
{
    private const AUDITED = [
        'id',
        'academic_year_id',
        'name',
        'code',
        'type',
        'sequence',
        'status',
        'description',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AcademicTerm::class);

        $query = AcademicTerm::query()
            ->with('academicYear')
            ->orderBy('sequence')
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($type = $request->input('type')) {
            if (in_array($type, AcademicTerm::TYPES, true)) {
                $query->where('type', $type);
            }
        }

        if (in_array($request->input('status'), AcademicTerm::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('academic_terms.index', [
            'academicTerms' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'types' => AcademicTerm::TYPES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AcademicTerm::class);

        return view('academic_terms.create', [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'types' => AcademicTerm::TYPES,
        ]);
    }

    public function store(StoreAcademicTermRequest $request, AuditLogService $audit): RedirectResponse
    {
        $academicTerm = AcademicTerm::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('academic_term.created', $academicTerm, [], $academicTerm->only(self::AUDITED));

        return redirect()->route('academic-terms.index')->with('success', 'Academic term created.');
    }

    public function edit(string $academic_term): View
    {
        $model = $this->findScoped($academic_term);
        $this->authorize('update', $model);

        return view('academic_terms.edit', [
            'academicTerm' => $model,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'types' => AcademicTerm::TYPES,
        ]);
    }

    public function update(UpdateAcademicTermRequest $request, string $academic_term, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($academic_term);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('academic_term.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('academic-terms.index')->with('success', 'Academic term updated.');
    }

    public function destroy(string $academic_term, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($academic_term);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('academic_term.deleted', $model, $snapshot, []);

        return redirect()->route('academic-terms.index')->with('success', 'Academic term deleted.');
    }

    private function findScoped(string $id): AcademicTerm
    {
        return AcademicTerm::query()->findOrFail($id);
    }
}
