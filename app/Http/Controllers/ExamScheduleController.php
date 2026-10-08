<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExamSchedule\StoreExamScheduleRequest;
use App\Http\Requests\ExamSchedule\UpdateExamScheduleRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamScheduleController extends Controller
{
    private const AUDITED = [
        'id',
        'examination_id',
        'academic_year_id',
        'academic_term_id',
        'program_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'campus_id',
        'exam_date',
        'start_time',
        'end_time',
        'room',
        'max_marks',
        'passing_marks',
        'status',
        'remarks',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamSchedule::class);

        return view('exam_schedules.index', [
            'schedules' => $this->filteredQuery($request)->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'examination_id' => $request->input('examination_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'program_id' => $request->input('program_id'),
            'section_id' => $request->input('section_id'),
            'subject_id' => $request->input('subject_id'),
            'status' => $request->input('status'),
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code', 'academic_year_id', 'academic_term_id']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => ExamSchedule::STATUSES,
        ]);
    }

    /**
     * The exam schedule filter pipeline, shared by the list and its CSV export.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = ExamSchedule::query()
            ->with(['examination', 'academicYear', 'academicTerm', 'program', 'section', 'subject', 'faculty', 'campus'])
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('room', 'like', "%{$search}%")
                    ->orWhereHas('examination', fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('subject', fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        if ($examinationId = $request->input('examination_id')) {
            $query->where('examination_id', $examinationId);
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

        if ($subId = $request->input('subject_id')) {
            $query->where('subject_id', $subId);
        }

        if ($status = $request->input('status')) {
            if (in_array($status, ExamSchedule::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * CSV of the filtered exam schedule, or of an authorized selection of it.
     *
     * Destination of the listing's bulk "Export selected" action. The handler
     * already re-queried and authorized the ids through ExamSchedulePolicy; this
     * endpoint shape-checks them again (ListSelection), re-applies the same
     * filters and re-resolves every id through the tenant-scoped query. Read
     * only — the schedule itself is never modified here.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', ExamSchedule::class);

        $query = $this->filteredQuery($request)->reorder('exam_schedules.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_schedules.id', $ids);
        }

        $audit->record('exam_schedules.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('exam-schedules-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Examination', 'Examination code', 'Exam date', 'Start time', 'End time',
                'Subject', 'Subject code', 'Program', 'Section', 'Room', 'Campus',
                'Invigilator', 'Max marks', 'Passing marks', 'Status', 'Remarks',
            ])
            ->map(function (ExamSchedule $schedule): array {
                return [
                    $schedule->examination?->name,
                    $schedule->examination?->code,
                    $schedule->exam_date?->format('Y-m-d'),
                    substr((string) $schedule->start_time, 0, 5),
                    substr((string) $schedule->end_time, 0, 5),
                    $schedule->subject?->name,
                    $schedule->subject?->code,
                    $schedule->program?->name,
                    $schedule->section?->name,
                    $schedule->room,
                    $schedule->campus?->name,
                    $schedule->faculty?->full_name,
                    $schedule->max_marks,
                    $schedule->passing_marks,
                    $schedule->status,
                    $schedule->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ExamSchedule::class);

        $selectedExamId = $request->input('examination_id');

        return view('exam_schedules.create', array_merge($this->formData(), [
            'selectedExamId' => $selectedExamId,
        ]));
    }

    public function store(StoreExamScheduleRequest $request, AuditLogService $audit): RedirectResponse
    {
        try {
            $schedule = ExamSchedule::create($request->validated() + [
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
        } catch (QueryException $e) {
            return back()->withInput()->withErrors([
                'subject_id' => 'This schedule entry for section, subject, date, and start time already exists.',
            ]);
        }

        $audit->record('exam_schedule.created', $schedule, [], $schedule->only(self::AUDITED));

        return redirect()->route('exam-schedules.index')->with('success', 'Exam schedule entry created.');
    }

    public function edit(string $exam_schedule): View
    {
        $model = $this->findScoped($exam_schedule);
        $this->authorize('update', $model);

        return view('exam_schedules.edit', array_merge($this->formData(), [
            'schedule' => $model,
        ]));
    }

    public function update(UpdateExamScheduleRequest $request, string $exam_schedule, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_schedule);
        $old = $model->only(self::AUDITED);

        try {
            $model->update($request->validated() + ['updated_by' => auth()->id()]);
        } catch (QueryException $e) {
            return back()->withInput()->withErrors([
                'subject_id' => 'This schedule entry for section, subject, date, and start time already exists.',
            ]);
        }

        $audit->record('exam_schedule.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('exam-schedules.index')->with('success', 'Exam schedule entry updated.');
    }

    public function destroy(string $exam_schedule, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($exam_schedule);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('exam_schedule.deleted', $model, $snapshot, []);

        return redirect()->route('exam-schedules.index')->with('success', 'Exam schedule entry deleted.');
    }

    private function findScoped(string $id): ExamSchedule
    {
        return ExamSchedule::query()->findOrFail($id);
    }

    private function formData(): array
    {
        return [
            'examinations' => Examination::query()->where('status', '!=', Examination::STATUS_CANCELLED)->orderByDesc('id')->get(['id', 'name', 'code', 'academic_year_id', 'academic_term_id']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'faculties' => Faculty::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => ExamSchedule::STATUSES,
        ];
    }
}
