<?php

namespace App\Http\Controllers;

use App\Domain\Academic\Services\FacultyWorkloadService;
use App\Models\AcademicAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTimetable;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Academic CSV exports — the streaming half of the six Academic bulk actions.
 *
 * Every method here is the destination of exactly one bulk action registered in
 * {@see \App\Providers\BulkActionServiceProvider} (`academic_subject_enrollments`,
 * `academic_sections`, `academic_timetables`, `academic_attendance`,
 * `academic_calendar`, `academic_workload`). The bulk action handler has already
 * re-queried the browser's ids inside the active college and authorized each
 * record; this controller treats those ids as a REQUEST again:
 *
 *  - `ListSelection` shape-checks them (positive integers only, de-duplicated,
 *    capped), so nothing but ids can reach a query;
 *  - the query itself carries the active college (the model's CollegeScope plus
 *    an explicit college_id predicate), so a foreign-college or soft-deleted id
 *    simply matches nothing;
 *  - the same permission the listing screen requires is re-checked here, so a
 *    hand-edited URL cannot export anything the screen would not show.
 *
 * Nothing in this controller mutates a record: it streams CSV from the shared
 * {@see CsvStreamExport}. No Aadhaar / government identity number, no document
 * or file path and no private student data is part of any column list below.
 */
class AcademicsExportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FacultyWorkloadService $workload,
    ) {
    }

    /**
     * Student subject enrollments as CSV.
     */
    public function subjectEnrollments(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_subject_enrollments.view');

        $query = AcademicSubjectEnrollment::query()
            ->where('academic_subject_enrollments.college_id', $collegeId)
            ->with(['student', 'subject', 'academicTerm', 'section'])
            ->orderBy('academic_subject_enrollments.id');

        $ids = $this->applySelection($query, $request, 'academic_subject_enrollments.id');

        $this->auditSelection($audit, $query, 'academic_subject_enrollments.exported', $ids);

        return CsvStreamExport::make($this->filename('subject-enrollments'))
            ->withHeaders([
                'Student number', 'Student', 'Subject', 'Subject code', 'Academic year',
                'Academic term', 'Section', 'Enrollment date', 'Status', 'Remarks',
            ])
            ->map(function (AcademicSubjectEnrollment $item): array {
                return [
                    $item->student?->student_number,
                    $item->student?->fullName(),
                    $item->subject?->name,
                    $item->subject?->code,
                    $item->academicYear?->name,
                    $item->academicTerm?->name,
                    $item->section?->name,
                    $item->enrollment_date?->format('Y-m-d'),
                    $item->status,
                    $item->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Class / section records as CSV — the section management screen's own
     * reference data plus its derived counts. Section master data stays in the
     * Platform module; nothing is written here.
     */
    public function sections(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_sections.view');

        $query = Section::query()
            ->where('sections.college_id', $collegeId)
            ->with(['academicYear', 'program', 'campus'])
            ->withCount('assignments')
            ->withCount(['assignments as subject_count' => fn ($q) => $q->select(DB::raw('count(distinct subject_id)'))])
            ->orderBy('sections.id');

        $ids = $this->applySelection($query, $request, 'sections.id');

        $this->auditSelection($audit, $query, 'academic_sections.exported', $ids);

        return CsvStreamExport::make($this->filename('sections'))
            ->withHeaders([
                'Section', 'Code', 'Academic year', 'Program', 'Campus', 'Capacity',
                'Status', 'Subjects', 'Faculty assignments', 'Description',
            ])
            ->map(function (Section $section): array {
                return [
                    $section->name,
                    $section->code,
                    $section->academicYear?->name,
                    $section->program?->name,
                    $section->campus?->name,
                    $section->capacity,
                    $section->status,
                    $section->subject_count,
                    $section->assignments_count,
                    $section->description,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Timetable entries as CSV.
     */
    public function timetables(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_timetables.view');

        $query = AcademicTimetable::query()
            ->where('academic_timetables.college_id', $collegeId)
            ->with(['academicYear', 'academicTerm', 'program', 'section', 'subject', 'faculty', 'campus'])
            ->orderBy('academic_timetables.id');

        $ids = $this->applySelection($query, $request, 'academic_timetables.id');

        $this->auditSelection($audit, $query, 'academic_timetables.exported', $ids);

        return CsvStreamExport::make($this->filename('timetables'))
            ->withHeaders([
                'Day', 'Period', 'Start time', 'End time', 'Academic year', 'Academic term',
                'Program', 'Section', 'Subject', 'Faculty', 'Room', 'Campus', 'Status', 'Remarks',
            ])
            ->map(function (AcademicTimetable $item): array {
                return [
                    $this->dayName($item->day_of_week),
                    $item->period,
                    substr((string) $item->start_time, 0, 5),
                    substr((string) $item->end_time, 0, 5),
                    $item->academicYear?->name,
                    $item->academicTerm?->name,
                    $item->program?->name,
                    $item->section?->name,
                    $item->subject?->name,
                    $item->faculty?->full_name,
                    $item->room,
                    $item->campus?->name,
                    $item->status,
                    $item->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Academic attendance rows as CSV.
     */
    public function attendance(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_attendance.view');

        $query = AcademicAttendance::query()
            ->where('academic_attendances.college_id', $collegeId)
            ->with(['student', 'studentEnrollment', 'subject', 'section', 'faculty', 'academicYear', 'academicTerm'])
            ->orderBy('academic_attendances.id');

        $ids = $this->applySelection($query, $request, 'academic_attendances.id');

        $this->auditSelection($audit, $query, 'academic_attendance.exported', $ids);

        return CsvStreamExport::make($this->filename('academic-attendance'))
            ->withHeaders([
                'Attendance date', 'Student number', 'Student', 'Enrollment number', 'Subject',
                'Section', 'Academic year', 'Academic term', 'Faculty', 'Status', 'Remarks',
            ])
            ->map(function (AcademicAttendance $item): array {
                return [
                    $item->attendance_date?->format('Y-m-d'),
                    $item->student?->student_number,
                    $item->student?->fullName(),
                    $item->studentEnrollment?->enrollment_number,
                    $item->subject?->name,
                    $item->section?->name,
                    $item->academicYear?->name,
                    $item->academicTerm?->name,
                    $item->faculty?->full_name,
                    $item->status,
                    $item->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Academic calendar events as CSV.
     */
    public function calendar(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_calendar.view');

        $query = AcademicCalendarEvent::query()
            ->where('academic_calendar_events.college_id', $collegeId)
            ->with(['academicYear', 'academicTerm'])
            ->orderBy('academic_calendar_events.id');

        $ids = $this->applySelection($query, $request, 'academic_calendar_events.id');

        $this->auditSelection($audit, $query, 'academic_calendar.exported', $ids);

        return CsvStreamExport::make($this->filename('academic-calendar'))
            ->withHeaders([
                'Event', 'Event type', 'Start date', 'End date', 'Academic year',
                'Academic term', 'Status', 'Description',
            ])
            ->map(function (AcademicCalendarEvent $event): array {
                return [
                    $event->title,
                    $event->event_type,
                    $event->start_date?->format('Y-m-d'),
                    $event->end_date?->format('Y-m-d'),
                    $event->academicYear?->name,
                    $event->academicTerm?->name,
                    $event->status,
                    $event->description,
                ];
            })
            ->streamFromQuery($query);
    }

    /**
     * Faculty workload lines as CSV.
     *
     * A workload line is derived, so the ids the bulk action authorized are the
     * representative timetable entries of the selected groups; each group is
     * re-derived here through the same service the screen uses, inside the
     * active college only. One CSV row per group — exactly what was selected.
     */
    public function workload(Request $request, AuditLogService $audit): StreamedResponse
    {
        $collegeId = $this->authorizeExport($request, 'academic_workload.view');

        $ids = ListSelection::ids($request->input('ids', []));

        $groups = $ids === [] ? collect() : $this->workload->groupsForRepresentatives($ids);

        $audit->record('academic_workload.exported', null, [], [
            'college_id' => $collegeId,
            'selected_ids' => count($ids),
            'rows' => $groups->count(),
        ]);

        return CsvStreamExport::make($this->filename('faculty-workload'))
            ->withHeaders([
                'Faculty', 'Employee code', 'Subject', 'Section', 'Academic year',
                'Academic term', 'Periods', 'Weekly hours',
            ])
            ->map(function ($group): array {
                return [
                    $group->faculty?->full_name,
                    $group->faculty?->employee_code,
                    $group->subject?->name,
                    $group->section?->name,
                    $group->academicYear?->name,
                    $group->academicTerm?->name,
                    $group->periods,
                    number_format((float) $group->weekly_hours, 2, '.', ''),
                ];
            })
            ->streamFromCollection($groups);
    }

    /**
     * Resolve the active college and re-check the screen's own permission.
     *
     * @return int The active college id.
     */
    private function authorizeExport(Request $request, string $permission): int
    {
        $collegeId = (int) $this->tenant->require()->id;

        abort_unless(
            $request->user()?->hasPermission($permission, $collegeId) ?? false,
            403,
        );

        return $collegeId;
    }

    /**
     * Narrow the query to an authorized selection, when one was supplied.
     *
     * @return array<int, int>
     */
    private function applySelection(Builder $query, Request $request, string $column): array
    {
        $ids = ListSelection::ids($request->input('ids', []));

        if ($ids !== []) {
            $query->whereIn($column, $ids);
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function auditSelection(AuditLogService $audit, Builder $query, string $action, array $ids): void
    {
        $audit->record($action, null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);
    }

    private function filename(string $name): string
    {
        return $name.'-export-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * The weekday label the timetable screen prints (1 = Monday … 7 = Sunday).
     */
    private function dayName(?int $day): string
    {
        return ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$day ?? 0] ?? '';
    }
}
