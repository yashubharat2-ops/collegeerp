@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $departmentLabel = in_array($report, ['faculty_subjects', 'workload'], true) ? 'Faculty department' : 'Department';
    $searchLabel = match ($report) {
        'faculty_subjects' => 'Search faculty / subject',
        'calendar' => 'Search event title',
        default => 'Search student',
    };
    $searchPlaceholder = match ($report) {
        'faculty_subjects' => 'Employee code, name or subject',
        'calendar' => 'Event title',
        default => 'Student number or name',
    };
    $dateLabel = match ($report) {
        'subject_enrollments' => 'Subject enrollment date',
        'calendar' => 'Event date',
        default => 'Attendance date',
    };
    $statusLabel = match ($report) {
        'section_strength' => 'Section status',
        'faculty_subjects' => 'Assignment status',
        'timetable' => 'Timetable entry status',
        'calendar' => 'Event status',
        default => 'Subject enrollment status',
    };
@endphp
<form method="GET" action="{{ route('academic-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
    <input type="hidden" name="report" value="{{ $report }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if($show('search'))
            <label class="text-sm text-slate-700">{{ $searchLabel }}
                <input class="input mt-1" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="{{ $searchPlaceholder }}">
            </label>
        @endif
        @if($show('academic_year_id'))
            <label class="text-sm text-slate-700">Academic year
                <select class="input mt-1" name="academic_year_id">
                    <option value="">All years</option>
                    @foreach($academicYears as $year)
                        <option value="{{ $year->id }}" @selected((string) $filters['academic_year_id'] === (string) $year->id)>{{ $year->name }} ({{ $year->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('academic_term_id'))
            <label class="text-sm text-slate-700">Term / Semester
                <select class="input mt-1" name="academic_term_id">
                    <option value="">All terms</option>
                    @foreach($academicTerms as $term)
                        <option value="{{ $term->id }}" @selected((string) $filters['academic_term_id'] === (string) $term->id)>{{ $term->name }} ({{ $term->code }}) · {{ $term->academicYear?->name ?? 'No year' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('department_id'))
            <label class="text-sm text-slate-700">{{ $departmentLabel }}
                <select class="input mt-1" name="department_id">
                    <option value="">All departments</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) $filters['department_id'] === (string) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('program_id'))
            <label class="text-sm text-slate-700">Program / Course
                <select class="input mt-1" name="program_id">
                    <option value="">All programs</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((string) $filters['program_id'] === (string) $program->id)>{{ $program->name }} ({{ $program->code }})@if($program->department) · {{ $program->department->name }}@endif</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('section_id'))
            <label class="text-sm text-slate-700">Class / Section
                <select class="input mt-1" name="section_id">
                    <option value="">All sections</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" @selected((string) $filters['section_id'] === (string) $section->id)>{{ $section->name }} · {{ $section->program?->name ?? 'No program' }} · {{ $section->academicYear?->name ?? 'No year' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('subject_id'))
            <label class="text-sm text-slate-700">Subject
                <select class="input mt-1" name="subject_id">
                    <option value="">All subjects</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" @selected((string) $filters['subject_id'] === (string) $subject->id)>{{ $subject->name }} ({{ $subject->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('faculty_id'))
            <label class="text-sm text-slate-700">Faculty
                <select class="input mt-1" name="faculty_id">
                    <option value="">All faculty</option>
                    @foreach($faculties as $faculty)
                        <option value="{{ $faculty->id }}" @selected((string) $filters['faculty_id'] === (string) $faculty->id)>{{ $faculty->full_name }} ({{ $faculty->employee_code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('day_of_week'))
            <label class="text-sm text-slate-700">Day
                <select class="input mt-1" name="day_of_week">
                    <option value="">All days</option>
                    @foreach($days as $number => $day)
                        <option value="{{ $number }}" @selected((string) $filters['day_of_week'] === (string) $number)>{{ $day }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('event_type'))
            <label class="text-sm text-slate-700">Event type
                <select class="input mt-1" name="event_type">
                    <option value="">All event types</option>
                    @foreach($eventTypes as $type)
                        <option value="{{ $type }}" @selected($filters['event_type'] === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="{{ $report === 'timetable' ? 'all' : '' }}" @selected(in_array($filters['status_choice'], [null, 'all'], true))>All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status_choice'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('attendance_status'))
            <label class="text-sm text-slate-700">Attendance status
                <select class="input mt-1" name="attendance_status">
                    <option value="">All statuses</option>
                    @foreach(\App\Domain\Academic\Services\AcademicReportService::ATTENDANCE_STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['attendance_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('group'))
            <label class="text-sm text-slate-700">Summarise by
                <select class="input mt-1" name="group">
                    <option value="subject" @selected($filters['group'] === 'subject')>Student + subject + term</option>
                    <option value="student" @selected($filters['group'] === 'student')>Student (all subjects)</option>
                </select>
            </label>
        @endif
        @if($show('below'))
            <label class="text-sm text-slate-700">Attendance below (%)
                <input class="input mt-1" type="number" name="below" min="0" max="100" step="0.01" value="{{ $filters['below'] }}" placeholder="e.g. 75">
            </label>
        @endif
        @if($show('from'))
            <label class="text-sm text-slate-700">{{ $dateLabel }} from
                <input class="input mt-1" type="date" name="from" value="{{ $filters['from'] }}">
            </label>
            <label class="text-sm text-slate-700">{{ $dateLabel }} to
                <input class="input mt-1" type="date" name="to" value="{{ $filters['to'] }}">
            </label>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="button">Apply filters</button>
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('academic-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
