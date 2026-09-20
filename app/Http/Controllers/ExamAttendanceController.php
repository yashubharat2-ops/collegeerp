<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExamAttendance\BulkExamAttendanceRequest;
use App\Http\Requests\ExamAttendance\StoreExamAttendanceRequest;
use App\Http\Requests\ExamAttendance\UpdateExamAttendanceRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ExamAttendance;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ExamEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Exam Attendance (Examinations Phase 2).
 *
 * Thin controller: validation lives in the Form Requests, eligibility in
 * ExamEligibilityService, and college_id always comes from the tenant
 * context — never from request data.
 */
class ExamAttendanceController extends Controller
{
    private const AUDITED = [
        'exam_schedule_id',
        'student_enrollment_id',
        'attendance_status',
        'marked_at',
        'remarks',
        'marked_by',
    ];

    public function __construct(private readonly ExamEligibilityService $eligibility)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamAttendance::class);

        $schedule = $request->filled('exam_schedule_id')
            ? ExamSchedule::query()->with(['examination', 'academicYear', 'academicTerm', 'program', 'section', 'subject', 'campus'])->find((int) $request->input('exam_schedule_id'))
            : null;

        if ($schedule) {
            return $this->scheduleBoard($request, $schedule);
        }

        $query = ExamAttendance::query()
            ->with([
                'examSchedule.examination',
                'examSchedule.subject',
                'examSchedule.section',
                'studentEnrollment.student',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'markedBy',
            ])
            // Deterministic pagination order.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('examination_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('examination_id', (int) $request->input('examination_id')));
        }
        if ($request->filled('academic_year_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('academic_year_id', (int) $request->input('academic_year_id')));
        }
        if ($request->filled('academic_term_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('academic_term_id', (int) $request->input('academic_term_id')));
        }
        if ($request->filled('program_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('program_id', (int) $request->input('program_id')));
        }
        if ($request->filled('section_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('section_id', (int) $request->input('section_id')));
        }
        if ($request->filled('subject_id')) {
            $query->whereHas('examSchedule', fn ($q) => $q->where('subject_id', (int) $request->input('subject_id')));
        }
        if ($request->filled('exam_date')) {
            $query->whereHas('examSchedule', fn ($q) => $q->whereDate('exam_date', $request->input('exam_date')));
        }
        if ($request->filled('attendance_status') && in_array($request->input('attendance_status'), ExamAttendance::STATUSES, true)) {
            $query->where('attendance_status', $request->input('attendance_status'));
        }

        return view('exam_attendance.index', array_merge($this->filterOptions(), [
            'attendances' => $query->paginate(15)->withQueryString(),
            'schedule' => null,
            'enrollments' => null,
            'existingByEnrollment' => collect(),
            'locked' => false,
            'filters' => $this->currentFilters($request),
        ]));
    }

    /**
     * Marking board for one selected exam schedule: the academically eligible
     * enrollments plus any attendance already recorded for them.
     */
    private function scheduleBoard(Request $request, ExamSchedule $schedule): View
    {
        $enrollments = $this->eligibility->eligibleEnrollments($schedule)
            ->with(['student', 'program', 'section'])
            // Deterministic pagination order.
            ->orderBy('enrollment_number')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $existingByEnrollment = ExamAttendance::query()
            ->where('exam_schedule_id', $schedule->id)
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('student_enrollment_id');

        $user = $request->user();
        $locked = $schedule->status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin();

        return view('exam_attendance.index', array_merge($this->filterOptions(), [
            'attendances' => null,
            'schedule' => $schedule,
            'enrollments' => $enrollments,
            'existingByEnrollment' => $existingByEnrollment,
            'locked' => $locked,
            'filters' => $this->currentFilters($request),
        ]));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ExamAttendance::class);

        $schedule = $request->filled('exam_schedule_id')
            ? ExamSchedule::query()->with(['examination', 'subject', 'section'])->find((int) $request->input('exam_schedule_id'))
            : null;

        $enrollments = $schedule
            ? $this->eligibility->eligibleEnrollments($schedule)->with('student')->orderBy('enrollment_number')->orderBy('id')->get()
            : collect();

        return view('exam_attendance.create', array_merge($this->formData(), [
            'selectedScheduleId' => $schedule?->id,
            'schedule' => $schedule,
            'enrollments' => $enrollments,
        ]));
    }

    public function store(StoreExamAttendanceRequest $request, AuditLogService $audit): RedirectResponse
    {
        $userId = auth()->id();

        try {
            // college_id is intentionally absent: BelongsToCollege stamps it
            // from the tenant context, never from client input.
            $attendance = ExamAttendance::create($request->validated() + [
                'marked_at' => now(),
                'marked_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } catch (QueryException) {
            // Partial unique index (SQLite/Postgres) rejected a racing insert.
            return back()->withInput()->withErrors([
                'student_enrollment_id' => 'Attendance for this student enrollment and exam schedule already exists.',
            ]);
        }

        $audit->record('exam_attendance.created', $attendance, [], $attendance->only(self::AUDITED));

        return redirect()
            ->route('exam-attendance.index', ['exam_schedule_id' => $attendance->exam_schedule_id])
            ->with('success', 'Exam attendance recorded.');
    }

    public function edit(string $exam_attendance): View
    {
        $model = $this->findScoped($exam_attendance);
        $this->authorize('update', $model);
        $model->load(['examSchedule.examination', 'examSchedule.subject', 'examSchedule.section', 'studentEnrollment.student']);

        return view('exam_attendance.edit', array_merge($this->formData(), [
            'attendance' => $model,
        ]));
    }

    public function update(UpdateExamAttendanceRequest $request, string $exam_attendance, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_attendance);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + [
            'marked_at' => now(),
            'marked_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('exam_attendance.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()
            ->route('exam-attendance.index', ['exam_schedule_id' => $model->exam_schedule_id])
            ->with('success', 'Exam attendance updated.');
    }

    public function destroy(string $exam_attendance, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_attendance);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('exam_attendance.deleted', $model, $snapshot, []);

        return redirect()
            ->route('exam-attendance.index', ['exam_schedule_id' => $model->exam_schedule_id])
            ->with('success', 'Exam attendance deleted.');
    }

    /**
     * Transactional bulk marking: every valid row succeeds or nothing is
     * written. Rows without a chosen status are untouched.
     */
    public function bulk(BulkExamAttendanceRequest $request, AuditLogService $audit): RedirectResponse
    {
        $schedule = ExamSchedule::query()->findOrFail((int) $request->input('exam_schedule_id'));

        // Mirrors the policy-level completed-schedule lock: bulk marking is
        // read-only for everyone except the global Super Admin once the
        // schedule is completed.
        if ($schedule->status === ExamSchedule::STATUS_COMPLETED && ! $request->user()->isSuperAdmin()) {
            abort(403, 'This exam schedule is completed; attendance is read-only.');
        }

        $userId = auth()->id();
        $counts = ['created' => 0, 'updated' => 0];

        DB::transaction(function () use ($request, $schedule, $userId, &$counts): void {
            foreach ($request->input('records', []) as $row) {
                $status = $row['attendance_status'] ?? null;
                if ($status === null || $status === '') {
                    continue;
                }

                $values = [
                    'attendance_status' => $status,
                    'remarks' => $row['remarks'] ?? null,
                    'marked_at' => now(),
                    'marked_by' => $userId,
                    'updated_by' => $userId,
                ];

                // updateOrCreate-style: repeated marking updates the existing
                // active row instead of creating duplicates.
                $existing = ExamAttendance::query()
                    ->where('exam_schedule_id', $schedule->id)
                    ->where('student_enrollment_id', (int) $row['student_enrollment_id'])
                    ->first();

                if ($existing) {
                    $existing->update($values);
                    $counts['updated']++;
                } else {
                    ExamAttendance::create($values + [
                        'exam_schedule_id' => $schedule->id,
                        'student_enrollment_id' => (int) $row['student_enrollment_id'],
                        'created_by' => $userId,
                    ]);
                    $counts['created']++;
                }
            }
        });

        // One traceable audit entry per bulk action instead of one noisy row
        // per student.
        $audit->record('exam_attendance.bulk_marked', $schedule, [], [
            'exam_schedule_id' => $schedule->id,
            'created' => $counts['created'],
            'updated' => $counts['updated'],
        ]);

        return redirect()
            ->route('exam-attendance.index', ['exam_schedule_id' => $schedule->id])
            ->with('success', "Exam attendance saved ({$counts['created']} marked, {$counts['updated']} updated).");
    }

    private function findScoped(string $id): ExamAttendance
    {
        return ExamAttendance::query()->findOrFail($id);
    }

    private function currentFilters(Request $request): array
    {
        return [
            'examination_id' => $request->input('examination_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'exam_schedule_id' => $request->input('exam_schedule_id'),
            'program_id' => $request->input('program_id'),
            'section_id' => $request->input('section_id'),
            'subject_id' => $request->input('subject_id'),
            'exam_date' => $request->input('exam_date'),
            'attendance_status' => $request->input('attendance_status'),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code']),
            'schedules' => ExamSchedule::query()
                ->with(['examination:id,name', 'subject:id,name,code', 'section:id,name'])
                ->orderByDesc('exam_date')
                ->orderByDesc('id')
                ->get(['id', 'examination_id', 'subject_id', 'section_id', 'exam_date', 'start_time']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => ExamAttendance::STATUSES,
        ];
    }

    private function formData(): array
    {
        return [
            'schedules' => ExamSchedule::query()
                ->with(['examination:id,name', 'subject:id,name,code', 'section:id,name'])
                ->orderByDesc('exam_date')
                ->orderByDesc('id')
                ->get(['id', 'examination_id', 'subject_id', 'section_id', 'exam_date', 'start_time']),
            'statuses' => ExamAttendance::STATUSES,
        ];
    }
}
