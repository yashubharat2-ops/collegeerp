<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ExamResult;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentResultHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student Result History — a student's published examination timeline
 * (Examinations Phase 4).
 *
 * Read-only by design: the timeline is derived live from the student's
 * published ExamResult rows across academic years and terms. Nothing is
 * duplicated into a history table — ExamResult / ExamResultItem remain the
 * single source of truth.
 *
 * Tenant isolation: Student carries CollegeScope, so the picker and the
 * timeline resolve inside the active college only; cross-college ids 404 via
 * findOrFail.
 */
class StudentResultHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentResultHistory::class);

        $query = Student::query()
            ->with(['enrollments.academicYear', 'enrollments.program'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('student_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('academic_year_id', $academicYearId));
        }

        if ($programId = $request->input('program_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('program_id', $programId));
        }

        return view('student_result_history.index', [
            'students' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function show(string $student): View
    {
        $model = Student::query()->findOrFail($student);

        $this->authorize('view', StudentResultHistory::forStudent($model));

        $model->load(['enrollments.academicYear', 'enrollments.program', 'enrollments.section']);

        $enrollmentIds = $model->enrollments->pluck('id')->all();

        $results = ExamResult::query()
            ->whereIn('student_enrollment_id', $enrollmentIds)
            ->whereNotNull('published_at')
            ->with([
                'examination',
                'academicYear',
                'academicTerm',
                'gradeScale',
                'studentEnrollment.student',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'items.examSchedule.subject',
                'items.subject',
                'publishedBy',
            ])
            ->get()
            ->sortBy(fn (ExamResult $result) => [
                $result->academicYear?->starts_on?->format('Y-m-d') ?? '9999-12-31',
                $result->academicTerm?->sequence ?? PHP_INT_MAX,
                $result->examination_id ?? PHP_INT_MAX,
                $result->id,
            ])
            ->values();

        return view('student_result_history.show', [
            'student' => $model,
            'results' => $results,
        ]);
    }
}
