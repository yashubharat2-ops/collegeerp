<?php

namespace App\Http\Controllers;

use App\Domain\Student\Filters\StudentFilter;
use App\Models\ExamResult;
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
    public function index(Request $request, StudentFilter $studentFilter): View
    {
        $this->authorize('viewAny', StudentResultHistory::class);
        $filters = $studentFilter->fromRequest($request, user: $request->user());

        $query = Student::query()
            ->approvedForOperations()
            ->with(['enrollments.academicYear', 'enrollments.program'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');
        $studentFilter->applyToStudents($query, $filters);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('student_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        return view('student_result_history.index', array_merge([
            'students' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
        ], $studentFilter->viewData($filters, StudentFilter::STUDENT_LIST_FIELDS, $request->user())));
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
