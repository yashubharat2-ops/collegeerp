<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultySubjectAssignment\StoreFacultySubjectAssignmentRequest;
use App\Http\Requests\FacultySubjectAssignment\UpdateFacultySubjectAssignmentRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\FacultySubjectAssignment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacultySubjectAssignmentController extends Controller
{
    private const AUDITED = [
        'id',
        'faculty_id',
        'subject_id',
        'academic_year_id',
        'academic_term_id',
        'program_id',
        'section_id',
        'status',
        'remarks',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FacultySubjectAssignment::class);

        $query = FacultySubjectAssignment::query()
            ->with(['faculty', 'subject', 'academicYear', 'academicTerm', 'program', 'section'])
            ->orderByDesc('id');

        if ($facultyId = $request->input('faculty_id')) {
            $query->where('faculty_id', $facultyId);
        }

        if ($subjectId = $request->input('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($yearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $yearId);
        }

        if ($termId = $request->input('academic_term_id')) {
            $query->where('academic_term_id', $termId);
        }

        if ($progId = $request->input('program_id')) {
            $query->where('program_id', $progId);
        }

        if ($secId = $request->input('section_id')) {
            $query->where('section_id', $secId);
        }

        if (in_array($request->input('status'), FacultySubjectAssignment::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('faculty_subject_assignments.index', [
            'assignments' => $query->paginate(15)->withQueryString(),
            'faculty_id' => $request->input('faculty_id'),
            'subject_id' => $request->input('subject_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'program_id' => $request->input('program_id'),
            'section_id' => $request->input('section_id'),
            'status' => $request->input('status'),
            'faculties' => Faculty::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', FacultySubjectAssignment::class);

        return view('faculty_subject_assignments.create', $this->formData());
    }

    public function store(StoreFacultySubjectAssignmentRequest $request, AuditLogService $audit): RedirectResponse
    {
        $assignment = FacultySubjectAssignment::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('faculty_subject_assignment.created', $assignment, [], $assignment->only(self::AUDITED));

        return redirect()->route('faculty-subject-assignments.index')->with('success', 'Faculty–subject assignment created.');
    }

    public function edit(string $faculty_subject_assignment): View
    {
        $model = $this->findScoped($faculty_subject_assignment);
        $this->authorize('update', $model);

        return view('faculty_subject_assignments.edit', array_merge($this->formData(), [
            'assignment' => $model,
        ]));
    }

    public function update(UpdateFacultySubjectAssignmentRequest $request, string $faculty_subject_assignment, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty_subject_assignment);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('faculty_subject_assignment.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('faculty-subject-assignments.index')->with('success', 'Faculty–subject assignment updated.');
    }

    public function destroy(string $faculty_subject_assignment, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty_subject_assignment);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('faculty_subject_assignment.deleted', $model, $snapshot, []);

        return redirect()->route('faculty-subject-assignments.index')->with('success', 'Faculty–subject assignment deleted.');
    }

    private function findScoped(string $id): FacultySubjectAssignment
    {
        return FacultySubjectAssignment::query()->findOrFail($id);
    }

    private function formData(): array
    {
        return [
            'faculties' => Faculty::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ];
    }
}
