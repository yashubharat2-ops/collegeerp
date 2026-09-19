<?php

namespace App\Http\Controllers;

use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Program;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionController extends Controller
{
    private const AUDITED = [
        'id',
        'academic_year_id',
        'program_id',
        'campus_id',
        'name',
        'code',
        'capacity',
        'status',
        'description',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Section::class);

        $query = Section::query()
            ->with(['academicYear', 'program', 'campus'])
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($programId = $request->input('program_id')) {
            $query->where('program_id', $programId);
        }

        if ($campusId = $request->input('campus_id')) {
            $query->where('campus_id', $campusId);
        }

        if (in_array($request->input('status'), Section::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('sections.index', [
            'sections' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'campus_id' => $request->input('campus_id'),
            'status' => $request->input('status'),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Section::class);

        return view('sections.create', [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(StoreSectionRequest $request, AuditLogService $audit): RedirectResponse
    {
        $section = Section::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('section.created', $section, [], $section->only(self::AUDITED));

        return redirect()->route('sections.index')->with('success', 'Section created.');
    }

    public function edit(string $section): View
    {
        $model = $this->findScoped($section);
        $this->authorize('update', $model);

        return view('sections.edit', [
            'section' => $model,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(UpdateSectionRequest $request, string $section, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($section);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('section.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('sections.index')->with('success', 'Section updated.');
    }

    public function destroy(string $section, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($section);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('section.deleted', $model, $snapshot, []);

        return redirect()->route('sections.index')->with('success', 'Section deleted.');
    }

    private function findScoped(string $id): Section
    {
        return Section::query()->findOrFail($id);
    }
}
