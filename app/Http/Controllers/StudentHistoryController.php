<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentHistoryService;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student history — the consolidated lifecycle view.
 *
 * Read-only by design: history is DERIVED from the modules that own the facts
 * (admission, student, enrollments, academic records, promotions, transfers,
 * documents) plus the append-only audit log. There is no history table to write
 * to and nothing here mutates any record.
 */
class StudentHistoryController extends Controller
{
    public function __construct(private readonly StudentHistoryService $history) {}

    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->hasPermission('student_history.view'), 403);

        $student = null;
        $events = collect();

        if ($studentId = $request->input('student_id')) {
            // Tenant-scoped: a foreign college's student 404s, never 403.
            $student = Student::query()->findOrFail($studentId);
            $this->authorize('view', $student);

            $events = $this->history->forStudent($student);
        }

        return view('student_history.index', [
            'student' => $student,
            'events' => $events,
            'student_id' => $request->input('student_id'),
            'students' => Student::query()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'student_number', 'first_name', 'last_name']),
            'recent' => $this->history->recentForCollege((int) app(TenantContext::class)->id()),
        ]);
    }

    public function show(string $student): View
    {
        abort_unless(auth()->user()?->hasPermission('student_history.view'), 403);

        $model = Student::query()->findOrFail($student);
        $this->authorize('view', $model);

        return view('student_history.show', [
            'student' => $model->load(['enrollments.academicYear', 'enrollments.program']),
            'events' => $this->history->forStudent($model),
        ]);
    }
}
