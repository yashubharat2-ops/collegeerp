<?php

namespace App\Domain\Academic\Services;

use App\Models\AcademicAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTerm;
use App\Models\AcademicTimetable;
use App\Models\Faculty;
use App\Models\FacultySubjectAssignment;
use App\Models\Section;
use App\Models\StudentEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Live, read-only Academic Reports over the existing Academic module tables
 * (subject enrollments, sections, faculty subject assignments, timetables,
 * attendance and calendar events) plus Student Enrollments.
 *
 * Nothing is persisted: no report tables, snapshots or cached aggregates. Every
 * root query and every correlated subquery is built from an Eloquent model that
 * carries CollegeScope (and SoftDeletes where the model has it), so all rows and
 * counts are restricted to the active college. Filter IDs from another college
 * therefore simply match nothing. Lists are paginated with a unique-ID
 * tiebreak; grouped reports aggregate in the database and eager-load labels.
 */
class AcademicReportService
{
    public const PER_PAGE = 20;

    /** Attendance statuses counted as "attended" for percentages. */
    public const ATTENDED_STATUSES = ['present', 'late'];

    /*
     * Status vocabularies of the existing Academic tables, mirroring the
     * validation rules in AcademicsController (the models define no constants).
     */
    public const ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'leave'];

    public const SUBJECT_ENROLLMENT_STATUSES = ['active', 'dropped', 'completed'];

    public const TIMETABLE_STATUSES = ['active', 'inactive'];

    public const CALENDAR_STATUSES = ['draft', 'published', 'cancelled'];

    /** 1. Enrollment / Subject Enrollment: one row per student-subject enrollment. */
    public function subjectEnrollments(array $filters): array
    {
        $base = $this->subjectEnrollmentQuery($filters);

        return [
            'rows' => (clone $base)
                ->with([
                    'student:id,student_number,first_name,middle_name,last_name,status',
                    'studentEnrollment:id,enrollment_number,status',
                    'academicYear:id,name,code', 'academicTerm:id,name,code',
                    'program:id,name,code,department_id', 'program.department:id,name',
                    'section:id,name,code', 'subject:id,name,code',
                ])
                ->orderByDesc('academic_subject_enrollments.enrollment_date')
                ->orderByDesc('academic_subject_enrollments.id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'studentsCount' => (clone $base)->distinct()->count('academic_subject_enrollments.student_id'),
            'subjectsCount' => (clone $base)->distinct()->count('academic_subject_enrollments.subject_id'),
        ];
    }

    /** 2. Class / Section Strength: one row per section with live counts. */
    public function sectionStrength(array $filters): array
    {
        $base = Section::query();
        if ($filters['academic_year_id']) {
            $base->where('sections.academic_year_id', $filters['academic_year_id']);
        }
        $this->programFilter($base, $filters, 'sections.program_id');
        if ($filters['section_id']) {
            $base->whereKey($filters['section_id']);
        }
        if ($filters['status']) {
            $base->where('sections.status', $filters['status']);
        }

        $term = $filters['academic_term_id'];
        $activeStudents = fn () => StudentEnrollment::query()
            ->where('student_enrollments.status', 'active');

        $rows = (clone $base)->select('sections.*')->addSelect([
            'active_students' => $activeStudents()
                ->selectRaw('COUNT(DISTINCT student_enrollments.student_id)')
                ->whereColumn('student_enrollments.section_id', 'sections.id'),
            'subject_students' => AcademicSubjectEnrollment::query()
                ->selectRaw('COUNT(DISTINCT academic_subject_enrollments.student_id)')
                ->whereColumn('academic_subject_enrollments.section_id', 'sections.id')
                ->where('academic_subject_enrollments.status', 'active')
                ->when($term, fn (Builder $q) => $q->where('academic_subject_enrollments.academic_term_id', $term)),
            'subjects_assigned' => FacultySubjectAssignment::query()
                ->selectRaw('COUNT(DISTINCT faculty_subject_assignments.subject_id)')
                ->whereColumn('faculty_subject_assignments.section_id', 'sections.id')
                ->where('faculty_subject_assignments.status', 'active')
                ->when($term, fn (Builder $q) => $q->where('faculty_subject_assignments.academic_term_id', $term)),
            'faculty_assigned' => FacultySubjectAssignment::query()
                ->selectRaw('COUNT(DISTINCT faculty_subject_assignments.faculty_id)')
                ->whereColumn('faculty_subject_assignments.section_id', 'sections.id')
                ->where('faculty_subject_assignments.status', 'active')
                ->when($term, fn (Builder $q) => $q->where('faculty_subject_assignments.academic_term_id', $term)),
            'weekly_periods' => AcademicTimetable::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('academic_timetables.section_id', 'sections.id')
                ->where('academic_timetables.status', 'active')
                ->when($term, fn (Builder $q) => $q->where('academic_timetables.academic_term_id', $term)),
        ])->with([
            'academicYear:id,name,code', 'program:id,name,code,department_id',
            'program.department:id,name', 'campus:id,name',
        ])->orderBy('sections.academic_year_id')->orderBy('sections.program_id')
            ->orderBy('sections.name')->orderBy('sections.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows' => $rows,
            'sectionsCount' => (clone $base)->count(),
            'capacityTotal' => (int) (clone $base)->sum('sections.capacity'),
            // Unique students across ALL matching sections (not the sum of rows).
            'studentsCount' => $activeStudents()
                ->whereIn('student_enrollments.section_id', (clone $base)->select('sections.id'))
                ->distinct()->count('student_enrollments.student_id'),
        ];
    }

    /** 3. Subject-wise Student: distinct students per subject / term / section. */
    public function subjectStudents(array $filters): array
    {
        $base = $this->subjectEnrollmentQuery($filters);
        $status = fn (string $value) => "COUNT(DISTINCT CASE WHEN academic_subject_enrollments.status = '{$value}' THEN academic_subject_enrollments.student_id END)";

        return [
            'rows' => (clone $base)
                ->selectRaw(implode(', ', [
                    'academic_subject_enrollments.subject_id',
                    'academic_subject_enrollments.academic_term_id',
                    'academic_subject_enrollments.section_id',
                    'COUNT(DISTINCT academic_subject_enrollments.student_id) AS students_count',
                    $status('active').' AS active_count',
                    $status('completed').' AS completed_count',
                    $status('dropped').' AS dropped_count',
                ]))
                ->groupBy('academic_subject_enrollments.subject_id', 'academic_subject_enrollments.academic_term_id', 'academic_subject_enrollments.section_id')
                ->with([
                    'subject:id,name,code,department_id', 'subject.department:id,name',
                    'academicTerm:id,name,code,academic_year_id', 'academicTerm.academicYear:id,name',
                    'section:id,name,code,program_id', 'section.program:id,name',
                ])
                ->orderBy('academic_subject_enrollments.subject_id')
                ->orderBy('academic_subject_enrollments.academic_term_id')
                ->orderBy('academic_subject_enrollments.section_id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'subjectsCount' => (clone $base)->distinct()->count('academic_subject_enrollments.subject_id'),
            'studentsCount' => (clone $base)->distinct()->count('academic_subject_enrollments.student_id'),
            'enrollmentsCount' => (clone $base)->count(),
        ];
    }

    /** 4. Faculty-wise Subject: faculty subject assignments with scheduled load. */
    public function facultySubjects(array $filters): array
    {
        $base = FacultySubjectAssignment::query();
        if ($filters['academic_year_id']) {
            $base->where('faculty_subject_assignments.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $base->where('faculty_subject_assignments.academic_term_id', $filters['academic_term_id']);
        }
        if ($filters['program_id']) {
            $base->where('faculty_subject_assignments.program_id', $filters['program_id']);
        }
        foreach (['section_id', 'subject_id', 'faculty_id'] as $field) {
            if ($filters[$field]) {
                $base->where('faculty_subject_assignments.'.$field, $filters[$field]);
            }
        }
        $this->facultyDepartment($base, $filters);
        if ($filters['status']) {
            $base->where('faculty_subject_assignments.status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $base->where(function (Builder $query) use ($like): void {
                $query->whereHas('faculty', fn (Builder $faculty) => $this->searchFaculty($faculty, $like))
                    ->orWhereHas('subject', fn (Builder $subject) => $subject->where(fn (Builder $s) => $s->where('subjects.name', 'like', $like)->orWhere('subjects.code', 'like', $like)));
            });
        }

        // An assignment without a term/section covers all terms/sections of
        // its year, so the correlated match treats NULL as a wildcard.
        $context = function (Builder $query, string $table): Builder {
            return $query
                ->whereColumn($table.'.subject_id', 'faculty_subject_assignments.subject_id')
                ->whereColumn($table.'.academic_year_id', 'faculty_subject_assignments.academic_year_id')
                ->where(fn (Builder $q) => $q->whereNull('faculty_subject_assignments.academic_term_id')
                    ->orWhereColumn($table.'.academic_term_id', 'faculty_subject_assignments.academic_term_id'))
                ->where(fn (Builder $q) => $q->whereNull('faculty_subject_assignments.section_id')
                    ->orWhereColumn($table.'.section_id', 'faculty_subject_assignments.section_id'));
        };

        $rows = (clone $base)->select('faculty_subject_assignments.*')->addSelect([
            'weekly_periods' => $context(AcademicTimetable::query(), 'academic_timetables')
                ->selectRaw('COUNT(*)')
                ->whereColumn('academic_timetables.faculty_id', 'faculty_subject_assignments.faculty_id')
                ->where('academic_timetables.status', 'active'),
            'enrolled_students' => $context(AcademicSubjectEnrollment::query(), 'academic_subject_enrollments')
                ->selectRaw('COUNT(DISTINCT academic_subject_enrollments.student_id)')
                ->where('academic_subject_enrollments.status', 'active'),
        ])->with([
            'faculty:id,employee_code,first_name,middle_name,last_name,department_id,status',
            'faculty.department:id,name', 'subject:id,name,code',
            'academicYear:id,name,code', 'academicTerm:id,name,code',
            'program:id,name,code', 'section:id,name,code',
        ])->orderBy(
            Faculty::query()->select('faculties.first_name')->whereColumn('faculties.id', 'faculty_subject_assignments.faculty_id')
        )->orderBy('faculty_subject_assignments.faculty_id')
            ->orderBy('faculty_subject_assignments.subject_id')
            ->orderBy('faculty_subject_assignments.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows' => $rows,
            'facultyCount' => (clone $base)->distinct()->count('faculty_subject_assignments.faculty_id'),
            'subjectsCount' => (clone $base)->distinct()->count('faculty_subject_assignments.subject_id'),
        ];
    }

    /** 5. Timetable: individual timetable entries in weekly order. */
    public function timetable(array $filters): array
    {
        $base = $this->timetableQuery($filters, true);

        return [
            'rows' => (clone $base)
                ->with([
                    'academicYear:id,name', 'academicTerm:id,name,code',
                    'program:id,name,code,department_id', 'program.department:id,name',
                    'section:id,name,code', 'subject:id,name,code',
                    'faculty:id,employee_code,first_name,middle_name,last_name', 'campus:id,name',
                ])
                ->orderBy('academic_timetables.day_of_week')->orderBy('academic_timetables.start_time')
                ->orderBy('academic_timetables.section_id')->orderBy('academic_timetables.id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'entriesCount' => (clone $base)->count(),
            'weeklyHours' => $this->hours((clone $base)->get(['academic_timetables.start_time', 'academic_timetables.end_time'])),
        ];
    }

    /** 6. Attendance: the date-wise subject attendance register. */
    public function attendance(array $filters): array
    {
        $base = $this->attendanceQuery($filters);
        if ($filters['attendance_status']) {
            $base->where('academic_attendances.status', $filters['attendance_status']);
        }

        $counts = (clone $base)->selectRaw('academic_attendances.status, COUNT(*) AS total')
            ->groupBy('academic_attendances.status')->pluck('total', 'status');

        return [
            'rows' => (clone $base)
                ->with([
                    'student:id,student_number,first_name,middle_name,last_name',
                    'subject:id,name,code', 'section:id,name,code', 'academicTerm:id,name,code',
                    'faculty:id,employee_code,first_name,middle_name,last_name',
                ])
                ->orderByDesc('academic_attendances.attendance_date')->orderByDesc('academic_attendances.id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'counts' => collect(self::ATTENDANCE_STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
        ];
    }

    /**
     * 7. Student Attendance Summary. Attendance % = (present + late) ÷ recorded
     * sessions. Grouped per student + subject + term, or per student overall.
     */
    public function studentAttendance(array $filters): array
    {
        $base = $this->attendanceQuery($filters);
        $perSubject = $filters['group'] !== 'student';
        $attended = "SUM(CASE WHEN academic_attendances.status IN ('present', 'late') THEN 1 ELSE 0 END)";
        $count = fn (string $status) => "SUM(CASE WHEN academic_attendances.status = '{$status}' THEN 1 ELSE 0 END)";
        $groups = $perSubject
            ? ['academic_attendances.student_id', 'academic_attendances.subject_id', 'academic_attendances.academic_term_id']
            : ['academic_attendances.student_id'];

        $query = (clone $base)->selectRaw(implode(', ', [
            ...$groups,
            'COUNT(*) AS sessions',
            $count('present').' AS present_count',
            $count('absent').' AS absent_count',
            $count('late').' AS late_count',
            $count('leave').' AS leave_count',
            "{$attended} AS attended_count",
        ]))->groupBy(...$groups);

        if ($filters['below'] !== null) {
            // attended / sessions < below%  ⇔  attended × 100 < sessions × below.
            // The binding stays inside arithmetic: PDO sends it as a string,
            // and SQLite would otherwise order every number before any TEXT.
            $query->havingRaw("({$attended} * 100.0) < (COUNT(*) * ?)", [(float) $filters['below']]);
        }

        foreach ($groups as $column) {
            $query->orderBy($column);
        }

        $sessions = (clone $base)->count();

        return [
            'rows' => $query->with($perSubject
                ? ['student:id,student_number,first_name,middle_name,last_name,status', 'subject:id,name,code', 'academicTerm:id,name,code']
                : ['student:id,student_number,first_name,middle_name,last_name,status'])
                ->paginate(self::PER_PAGE)->withQueryString(),
            'perSubject' => $perSubject,
            'studentsCount' => (clone $base)->distinct()->count('academic_attendances.student_id'),
            'sessionsCount' => $sessions,
            'overallPercentage' => $sessions === 0 ? null
                : round((clone $base)->whereIn('academic_attendances.status', self::ATTENDED_STATUSES)->count() * 100 / $sessions, 2),
        ];
    }

    /** 8. Faculty Workload: active weekly timetable load per faculty / year / term. */
    public function workload(array $filters): array
    {
        $base = $this->timetableQuery($filters, false)->where('academic_timetables.status', 'active');
        $groups = ['academic_timetables.faculty_id', 'academic_timetables.academic_year_id', 'academic_timetables.academic_term_id'];

        $rows = (clone $base)->selectRaw(implode(', ', [
            ...$groups,
            'COUNT(*) AS weekly_periods',
            'COUNT(DISTINCT academic_timetables.subject_id) AS subjects_count',
            'COUNT(DISTINCT academic_timetables.section_id) AS sections_count',
        ]))->groupBy(...$groups)->with([
            'faculty:id,employee_code,first_name,middle_name,last_name,department_id,status',
            'faculty.department:id,name', 'academicYear:id,name', 'academicTerm:id,name,code',
        ])->orderBy('academic_timetables.faculty_id')
            ->orderBy('academic_timetables.academic_year_id')
            ->orderBy('academic_timetables.academic_term_id')
            ->paginate(self::PER_PAGE)->withQueryString();

        // Durations are summed in PHP (SQLite and MySQL share no portable time
        // difference function) from ONE query of the matching entries — never
        // one query per faculty row.
        $entries = (clone $base)->get([
            'academic_timetables.faculty_id', 'academic_timetables.academic_year_id',
            'academic_timetables.academic_term_id', 'academic_timetables.start_time', 'academic_timetables.end_time',
        ]);
        $hours = $entries->groupBy(fn ($e) => $e->faculty_id.'|'.$e->academic_year_id.'|'.$e->academic_term_id)
            ->map(fn (Collection $group) => $this->hours($group));

        // Active assignments for the faculty on this page, in one grouped query.
        $facultyIds = $rows->getCollection()->pluck('faculty_id')->unique()->values()->all();
        $assignments = $facultyIds === [] ? collect() : FacultySubjectAssignment::query()
            ->where('faculty_subject_assignments.status', 'active')
            ->whereIn('faculty_subject_assignments.faculty_id', $facultyIds)
            ->when($filters['program_id'], fn (Builder $q) => $q->where('faculty_subject_assignments.program_id', $filters['program_id']))
            ->when($filters['section_id'], fn (Builder $q) => $q->where('faculty_subject_assignments.section_id', $filters['section_id']))
            ->when($filters['subject_id'], fn (Builder $q) => $q->where('faculty_subject_assignments.subject_id', $filters['subject_id']))
            ->selectRaw('faculty_id, academic_year_id, academic_term_id, COUNT(*) AS total')
            ->groupBy('faculty_id', 'academic_year_id', 'academic_term_id')
            ->toBase()->get();

        $rows->getCollection()->each(function ($row) use ($hours, $assignments): void {
            $row->weekly_hours = $hours->get($row->faculty_id.'|'.$row->academic_year_id.'|'.$row->academic_term_id, 0.0);
            // Assignments without a term apply to every term of the year.
            $row->assignments_count = (int) $assignments
                ->filter(fn ($a) => (int) $a->faculty_id === (int) $row->faculty_id
                    && (int) $a->academic_year_id === (int) $row->academic_year_id
                    && ($a->academic_term_id === null || (int) $a->academic_term_id === (int) $row->academic_term_id))
                ->sum('total');
        });

        return [
            'rows' => $rows,
            'facultyCount' => $entries->pluck('faculty_id')->unique()->count(),
            'periodsCount' => $entries->count(),
            'hoursTotal' => $this->hours($entries),
        ];
    }

    /** 9. Academic Calendar: events overlapping the optional date window. */
    public function calendar(array $filters): array
    {
        $base = AcademicCalendarEvent::query();
        if ($filters['academic_year_id']) {
            $base->where('academic_calendar_events.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            // Year-wide events (no term) belong to every term of their year.
            $term = $filters['academic_term_id'];
            $base->where(fn (Builder $q) => $q->where('academic_calendar_events.academic_term_id', $term)
                ->orWhere(fn (Builder $yearWide) => $yearWide->whereNull('academic_calendar_events.academic_term_id')
                    ->whereIn('academic_calendar_events.academic_year_id', AcademicTerm::query()->whereKey($term)->select('academic_terms.academic_year_id'))));
        }
        if ($filters['event_type']) {
            $base->where('academic_calendar_events.event_type', $filters['event_type']);
        }
        if ($filters['status']) {
            $base->where('academic_calendar_events.status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $base->where('academic_calendar_events.title', 'like', '%'.$filters['search'].'%');
        }
        // Overlap with [from, to]: an event spanning the window is included.
        if ($filters['from']) {
            $base->where('academic_calendar_events.end_date', '>=', $filters['from']);
        }
        if ($filters['to']) {
            $base->where('academic_calendar_events.start_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }

        $counts = (clone $base)->selectRaw('academic_calendar_events.status, COUNT(*) AS total')
            ->groupBy('academic_calendar_events.status')->pluck('total', 'status');

        return [
            'rows' => (clone $base)->with(['academicYear:id,name,code', 'academicTerm:id,name,code'])
                ->orderBy('academic_calendar_events.start_date')->orderBy('academic_calendar_events.end_date')
                ->orderBy('academic_calendar_events.id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'counts' => collect(self::CALENDAR_STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
        ];
    }

    /** Minutes between two H:i[:s] times; an end before start wraps past midnight. */
    public static function minutes(?string $start, ?string $end): int
    {
        if (! $start || ! $end) {
            return 0;
        }
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        if ($to->lessThan($from)) {
            $to = $to->addDay();
        }

        return (int) $from->diffInMinutes($to);
    }

    private function hours(Collection $entries): float
    {
        return round($entries->sum(fn ($e) => self::minutes($e->start_time, $e->end_time)) / 60, 2);
    }

    private function subjectEnrollmentQuery(array $filters): Builder
    {
        $query = AcademicSubjectEnrollment::query();
        if ($filters['academic_year_id']) {
            $query->where('academic_subject_enrollments.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('academic_subject_enrollments.academic_term_id', $filters['academic_term_id']);
        }
        $this->programFilter($query, $filters, 'academic_subject_enrollments.program_id');
        foreach (['section_id', 'subject_id'] as $field) {
            if ($filters[$field]) {
                $query->where('academic_subject_enrollments.'.$field, $filters[$field]);
            }
        }
        if ($filters['status']) {
            $query->where('academic_subject_enrollments.status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $query->whereHas('student', fn (Builder $student) => $this->searchStudent($student, $like));
        }
        $this->dateRange($query, $filters, 'academic_subject_enrollments.enrollment_date');

        return $query;
    }

    private function timetableQuery(array $filters, bool $programDepartment): Builder
    {
        $query = AcademicTimetable::query();
        if ($filters['academic_year_id']) {
            $query->where('academic_timetables.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('academic_timetables.academic_term_id', $filters['academic_term_id']);
        }
        if ($programDepartment) {
            $this->programFilter($query, $filters, 'academic_timetables.program_id');
        } else {
            if ($filters['program_id']) {
                $query->where('academic_timetables.program_id', $filters['program_id']);
            }
            $this->facultyDepartment($query, $filters);
        }
        foreach (['section_id', 'subject_id', 'faculty_id', 'day_of_week'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where('academic_timetables.'.$field, $filters[$field]);
            }
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('academic_timetables.status', $filters['status']);
        }

        return $query;
    }

    private function attendanceQuery(array $filters): Builder
    {
        $query = AcademicAttendance::query();
        if ($filters['academic_year_id']) {
            $query->where('academic_attendances.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('academic_attendances.academic_term_id', $filters['academic_term_id']);
        }
        foreach (['section_id', 'subject_id', 'faculty_id'] as $field) {
            if ($filters[$field]) {
                $query->where('academic_attendances.'.$field, $filters[$field]);
            }
        }
        // Attendance rows carry the section; program/department come from it.
        if ($filters['program_id'] || $filters['department_id']) {
            $query->whereHas('section', function (Builder $section) use ($filters): void {
                $this->programFilter($section, $filters, 'sections.program_id');
            });
        }
        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $query->whereHas('student', fn (Builder $student) => $this->searchStudent($student, $like));
        }
        $this->dateRange($query, $filters, 'academic_attendances.attendance_date');

        return $query;
    }

    /** Program by column; Department through the (tenant-scoped) Program. */
    private function programFilter(Builder $query, array $filters, string $programColumn): Builder
    {
        if ($filters['program_id']) {
            $query->where($programColumn, $filters['program_id']);
        }
        if ($filters['department_id']) {
            $query->whereHas('program', fn (Builder $program) => $program->where('programs.department_id', $filters['department_id']));
        }

        return $query;
    }

    /** Faculty-centric reports filter Department by the faculty's department. */
    private function facultyDepartment(Builder $query, array $filters): Builder
    {
        if ($filters['department_id']) {
            $query->whereHas('faculty', fn (Builder $faculty) => $faculty->where('faculties.department_id', $filters['department_id']));
        }

        return $query;
    }

    private function searchStudent(Builder $query, string $like): Builder
    {
        return $query->where(function (Builder $student) use ($like): void {
            $student->where('students.student_number', 'like', $like)
                ->orWhere('students.first_name', 'like', $like)
                ->orWhere('students.middle_name', 'like', $like)
                ->orWhere('students.last_name', 'like', $like);
        });
    }

    private function searchFaculty(Builder $query, string $like): Builder
    {
        return $query->where(function (Builder $faculty) use ($like): void {
            $faculty->where('faculties.employee_code', 'like', $like)
                ->orWhere('faculties.first_name', 'like', $like)
                ->orWhere('faculties.middle_name', 'like', $like)
                ->orWhere('faculties.last_name', 'like', $like);
        });
    }

    private function dateRange(Builder $query, array $filters, string $column): Builder
    {
        if ($filters['from']) {
            $query->where($column, '>=', $filters['from']);
        }
        if ($filters['to']) {
            // Half-open upper bound covers the whole final day even when the
            // date cast stores a midnight time suffix (as on SQLite).
            $query->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }

        return $query;
    }
}
