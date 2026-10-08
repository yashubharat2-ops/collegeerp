<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExamMark\BulkExamMarkRequest;
use App\Http\Requests\ExamMark\StoreExamMarkRequest;
use App\Http\Requests\ExamMark\UpdateExamMarkRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamMark;
use App\Models\ExamSchedule;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ExamEligibilityService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marks Entry (Examinations Phase 2).
 *
 * Thin controller: validation lives in the Form Requests, eligibility in
 * ExamEligibilityService, and college_id always comes from the tenant
 * context — never from request data. Phase 2 stops at data capture
 * (draft / entered / absent / withheld); there is no result publishing.
 */
class ExamMarkController extends Controller
{
    private const AUDITED = [
        'exam_schedule_id',
        'student_enrollment_id',
        'max_marks',
        'passing_marks',
        'obtained_marks',
        'remarks',
        'status',
        'entered_at',
        'entered_by',
    ];

    public function __construct(private readonly ExamEligibilityService $eligibility)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamMark::class);

        $schedule = $request->filled('exam_schedule_id')
            ? ExamSchedule::query()->with(['examination', 'academicYear', 'academicTerm', 'program', 'section', 'subject', 'campus'])->find((int) $request->input('exam_schedule_id'))
            : null;

        if ($schedule) {
            return $this->scheduleBoard($request, $schedule);
        }

        return view('exam_marks.index', array_merge($this->filterOptions(), [
            'marks' => $this->listQuery($request)->paginate(15)->withQueryString(),
            'schedule' => null,
            'enrollments' => null,
            'existingByEnrollment' => collect(),
            'locked' => false,
            'filters' => $this->currentFilters($request),
        ]));
    }

    /**
     * The marks RECORD list query (the mode without a selected schedule).
     *
     * Shared by `index()` and `export()`. The entry grid is deliberately not part
     * of it: that mode lists eligible enrollments with input controls.
     */
    private function listQuery(Request $request): Builder
    {
        $query = ExamMark::query()
            ->with([
                'examSchedule.examination',
                'examSchedule.subject',
                'examSchedule.section',
                'studentEnrollment.student',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'enteredBy',
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
        if ($request->filled('status') && in_array($request->input('status'), ExamMark::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return $query;
    }

    /**
     * CSV of the filtered marks entries, or of an authorized selection of them.
     *
     * Destination of the listing's bulk "Export selected" action: the handler
     * re-queried and authorized each id through ExamMarkPolicy, and this endpoint
     * re-applies the same filters and re-resolves the ids inside the active
     * college.
     *
     * Marks are THE source of truth for every calculated result, so this module
     * is strictly export-only: no bulk marks edit, no bulk pass/fail override and
     * no bulk status change exists — entering, correcting and deleting marks
     * stays on the existing per-record, validated and audited paths.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', ExamMark::class);

        $query = $this->listQuery($request)->reorder('exam_marks.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_marks.id', $ids);
        }

        $audit->record('exam_marks.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('exam-marks-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Examination', 'Subject', 'Exam date', 'Enrollment number', 'Student number',
                'Student', 'Program', 'Section', 'Obtained marks', 'Max marks', 'Passing marks',
                'Status', 'Entered by', 'Entered at', 'Remarks',
            ])
            ->map(function (ExamMark $mark): array {
                $schedule = $mark->examSchedule;
                $enrollment = $mark->studentEnrollment;

                return [
                    $schedule?->examination?->name,
                    $schedule?->subject?->name,
                    $schedule?->exam_date?->format('Y-m-d'),
                    $enrollment?->enrollment_number,
                    $enrollment?->student?->student_number,
                    $enrollment?->student?->fullName(),
                    $enrollment?->program?->name,
                    $enrollment?->section?->name,
                    $mark->obtained_marks,
                    $mark->max_marks,
                    $mark->passing_marks,
                    $mark->status,
                    $mark->enteredBy?->name,
                    $mark->entered_at?->format('Y-m-d H:i'),
                    $mark->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Entry grid for one selected exam schedule: the academically eligible
     * enrollments plus any marks already captured for them.
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

        $existingByEnrollment = ExamMark::query()
            ->where('exam_schedule_id', $schedule->id)
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('student_enrollment_id');

        $user = $request->user();
        $locked = $schedule->status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin();

        return view('exam_marks.index', array_merge($this->filterOptions(), [
            'marks' => null,
            'schedule' => $schedule,
            'enrollments' => $enrollments,
            'existingByEnrollment' => $existingByEnrollment,
            'locked' => $locked,
            'filters' => $this->currentFilters($request),
        ]));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ExamMark::class);

        $schedule = $request->filled('exam_schedule_id')
            ? ExamSchedule::query()->with(['examination', 'subject', 'section'])->find((int) $request->input('exam_schedule_id'))
            : null;

        $enrollments = $schedule
            ? $this->eligibility->eligibleEnrollments($schedule)->with('student')->orderBy('enrollment_number')->orderBy('id')->get()
            : collect();

        return view('exam_marks.create', array_merge($this->formData(), [
            'selectedScheduleId' => $schedule?->id,
            'schedule' => $schedule,
            'enrollments' => $enrollments,
        ]));
    }

    public function store(StoreExamMarkRequest $request, AuditLogService $audit): RedirectResponse
    {
        $userId = auth()->id();
        $data = $request->validated();
        $finalized = $data['status'] !== ExamMark::STATUS_DRAFT;

        try {
            // college_id is intentionally absent: BelongsToCollege stamps it
            // from the tenant context, never from client input.
            $mark = ExamMark::create($data + [
                'entered_at' => $finalized ? now() : null,
                'entered_by' => $finalized ? $userId : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } catch (QueryException) {
            // Partial unique index (SQLite/Postgres) rejected a racing insert.
            return back()->withInput()->withErrors([
                'student_enrollment_id' => 'Marks for this student enrollment and exam schedule already exist.',
            ]);
        }

        $audit->record('exam_marks.created', $mark, [], $mark->only(self::AUDITED));

        return redirect()
            ->route('exam-marks.index', ['exam_schedule_id' => $mark->exam_schedule_id])
            ->with('success', 'Marks recorded.');
    }

    public function edit(string $exam_mark): View
    {
        $model = $this->findScoped($exam_mark);
        $this->authorize('update', $model);
        $model->load(['examSchedule.examination', 'examSchedule.subject', 'examSchedule.section', 'studentEnrollment.student']);

        return view('exam_marks.edit', array_merge($this->formData(), [
            'mark' => $model,
        ]));
    }

    public function update(UpdateExamMarkRequest $request, string $exam_mark, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_mark);
        $old = $model->only(self::AUDITED);

        $data = $request->validated();
        $finalized = $data['status'] !== ExamMark::STATUS_DRAFT;

        $model->update($data + [
            // Leaving the draft state (entered / absent / withheld) is a
            // marker decision and gets stamped; reverting to draft clears it.
            'entered_at' => $finalized ? ($model->entered_at ?? now()) : null,
            'entered_by' => $finalized ? ($model->entered_by ?? auth()->id()) : null,
            'updated_by' => auth()->id(),
        ]);

        $audit->record('exam_marks.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()
            ->route('exam-marks.index', ['exam_schedule_id' => $model->exam_schedule_id])
            ->with('success', 'Marks updated.');
    }

    public function destroy(string $exam_mark, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_mark);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('exam_marks.deleted', $model, $snapshot, []);

        return redirect()
            ->route('exam-marks.index', ['exam_schedule_id' => $model->exam_schedule_id])
            ->with('success', 'Marks entry deleted.');
    }

    /**
     * Transactional bulk save: every valid row succeeds or nothing is
     * written. Rows without marks and without a status are untouched.
     */
    public function bulk(BulkExamMarkRequest $request, AuditLogService $audit): RedirectResponse
    {
        $schedule = ExamSchedule::query()->findOrFail((int) $request->input('exam_schedule_id'));

        // Mirrors the policy-level completed-schedule lock: bulk saving is
        // read-only for everyone except the global Super Admin once the
        // schedule is completed.
        if ($schedule->status === ExamSchedule::STATUS_COMPLETED && ! $request->user()->isSuperAdmin()) {
            abort(403, 'This exam schedule is completed; marks are read-only.');
        }

        $userId = auth()->id();
        $counts = ['created' => 0, 'updated' => 0];

        DB::transaction(function () use ($request, $schedule, $userId, &$counts): void {
            foreach ($request->input('records', []) as $row) {
                $status = $row['status'] ?? null;
                $status = ($status === '' ? null : $status);
                $obtained = $row['obtained_marks'] ?? null;
                $obtained = ($obtained === '' ? null : $obtained);

                if ($status === null && $obtained === null) {
                    continue;
                }

                $effectiveStatus = $status ?? ExamMark::STATUS_ENTERED;
                $scoreless = in_array($effectiveStatus, ExamMark::SCORELESS_STATUSES, true);

                $values = [
                    'max_marks' => $row['max_marks'],
                    'passing_marks' => $row['passing_marks'],
                    // Absent / withheld rows keep obtained_marks NULL (see
                    // ExamMark model).
                    'obtained_marks' => $scoreless ? null : $obtained,
                    'remarks' => $row['remarks'] ?? null,
                    'status' => $effectiveStatus,
                    'updated_by' => $userId,
                ];

                if ($effectiveStatus !== ExamMark::STATUS_DRAFT) {
                    $values['entered_at'] = now();
                    $values['entered_by'] = $userId;
                }

                // updateOrCreate-style: re-saving updates the existing active
                // row instead of creating duplicates.
                $existing = ExamMark::query()
                    ->where('exam_schedule_id', $schedule->id)
                    ->where('student_enrollment_id', (int) $row['student_enrollment_id'])
                    ->first();

                if ($existing) {
                    if ($effectiveStatus === ExamMark::STATUS_DRAFT) {
                        $values['entered_at'] = null;
                        $values['entered_by'] = null;
                    } else {
                        $values['entered_at'] = $existing->entered_at ?? now();
                        $values['entered_by'] = $existing->entered_by ?? $userId;
                    }
                    $existing->update($values);
                    $counts['updated']++;
                } else {
                    ExamMark::create($values + [
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
        $audit->record('exam_marks.bulk_saved', $schedule, [], [
            'exam_schedule_id' => $schedule->id,
            'created' => $counts['created'],
            'updated' => $counts['updated'],
        ]);

        return redirect()
            ->route('exam-marks.index', ['exam_schedule_id' => $schedule->id])
            ->with('success', "Marks saved ({$counts['created']} created, {$counts['updated']} updated).");
    }

    private function findScoped(string $id): ExamMark
    {
        return ExamMark::query()->findOrFail($id);
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
            'status' => $request->input('status'),
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
            'statuses' => ExamMark::STATUSES,
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
            'statuses' => ExamMark::STATUSES,
        ];
    }
}
