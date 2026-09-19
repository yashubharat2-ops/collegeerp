<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentAcademicRecordService;
use App\Http\Requests\StudentAcademicRecord\StoreStudentAcademicRecordRequest;
use App\Http\Requests\StudentAcademicRecord\UpdateStudentAcademicRecordRequest;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student Academic Records.
 *
 * Thin controller: authorize → tenant-scoped lookup → delegate to
 * StudentAcademicRecordService, which owns the transactional, tenant-safe
 * persistence and the contextual validation of every reference.
 */
class StudentAcademicRecordController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentAcademicRecord::class);

        // Deterministic ordering (oldest first) with an id tiebreak.
        $query = StudentAcademicRecord::query()
            ->with(['student', 'academicYear', 'academicTerm', 'program', 'section'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($studentId = $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($programId = $request->input('program_id')) {
            $query->where('program_id', $programId);
        }

        if (in_array($request->input('academic_status'), StudentAcademicRecord::ACADEMIC_STATUSES, true)) {
            $query->where('academic_status', $request->input('academic_status'));
        }

        return view('student_academic_records.index', [
            'records' => $query->paginate(15)->withQueryString(),
            'student_id' => $request->input('student_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'academic_status' => $request->input('academic_status'),
            'students' => $this->studentOptions(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentAcademicRecord::class);

        return view('student_academic_records.create', [
            'students' => $this->studentOptions(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => \App\Models\AcademicTerm::query()->with('academicYear:id,name')->orderBy('sequence')->get(),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => $this->sectionOptions(),
            'enrollments' => $this->enrollmentOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    public function store(StoreStudentAcademicRecordRequest $request, StudentAcademicRecordService $service): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $record = $service->create($request->validated(), $collegeId, auth()->id());

        return redirect()->route('student-academic-records.index')
            ->with('success', 'Academic record created for '.$record->periodLabel().'.');
    }

    public function edit(string $student_academic_record): View
    {
        $model = $this->findScoped($student_academic_record);
        $this->authorize('update', $model);

        return view('student_academic_records.edit', [
            'record' => $model->load(['student', 'academicYear', 'academicTerm', 'program', 'section']),
            'students' => $this->studentOptions(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => \App\Models\AcademicTerm::query()->with('academicYear:id,name')->orderBy('sequence')->get(),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => $this->sectionOptions(),
            // Limited to this record's student: the student is immutable.
            'enrollments' => $this->enrollmentOptions((int) $model->student_id),
        ]);
    }

    public function update(UpdateStudentAcademicRecordRequest $request, string $student_academic_record, StudentAcademicRecordService $service): RedirectResponse
    {
        $model = $this->findScoped($student_academic_record);
        $collegeId = app(TenantContext::class)->id();

        $service->update($model, $request->validated(), $collegeId, auth()->id());

        return back()->with('success', 'Academic record updated.');
    }

    public function destroy(string $student_academic_record, StudentAcademicRecordService $service): RedirectResponse
    {
        $model = $this->findScoped($student_academic_record);
        $this->authorize('delete', $model);

        $service->delete($model);

        return redirect()->route('student-academic-records.index')->with('success', 'Academic record deleted.');
    }

    /**
     * Tenant-safe lookup: a cross-college id is a 404, never a 403 leak.
     */
    private function findScoped(string $id): StudentAcademicRecord
    {
        return StudentAcademicRecord::query()->findOrFail($id);
    }

    /**
     * Enrollments offered as the optional link target. On edit this is limited
     * to the record's own student (the student is immutable).
     */
    private function enrollmentOptions(?int $studentId = null)
    {
        return \App\Models\StudentEnrollment::query()
            ->with(['student:id,student_number', 'academicYear:id,name', 'program:id,name'])
            ->when($studentId, fn ($query) => $query->where('student_id', $studentId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function studentOptions()
    {
        return Student::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name']);
    }

    /**
     * Sections are listed with their year/program context so the operator can
     * see what a section belongs to; the server re-validates the combination.
     */
    private function sectionOptions()
    {
        return Section::query()
            ->with(['academicYear:id,name', 'program:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
    }
}
