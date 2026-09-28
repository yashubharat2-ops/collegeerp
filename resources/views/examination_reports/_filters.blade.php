@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $searchLabel = $report === 'summary' ? 'Search examination' : 'Search student';
    $searchPlaceholder = $report === 'summary' ? 'Exam name or code' : 'Student number or name';
    $statusLabel = match ($report) {
        'summary', 'publishing' => 'Examination status',
        'schedule' => 'Schedule status',
        'attendance' => 'Attendance status',
        'marks' => 'Mark entry status',
        default => 'Result status',
    };
@endphp
<form method="GET" action="{{ route('examination-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
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
        @if($show('examination_id'))
            <label class="text-sm text-slate-700">Examination
                <select class="input mt-1" name="examination_id">
                    <option value="">All examinations</option>
                    @foreach($examinations as $examination)
                        <option value="{{ $examination->id }}" @selected((string) $filters['examination_id'] === (string) $examination->id)>{{ $examination->name }} ({{ $examination->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('department_id'))
            <label class="text-sm text-slate-700">Department
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
        @if($show('grade'))
            <label class="text-sm text-slate-700">Grade
                <select class="input mt-1" name="grade">
                    <option value="">All grades</option>
                    @foreach($grades as $grade)
                        <option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status') && $statusKey === 'status')
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('attendance_status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="attendance_status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['attendance_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('mark_status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="mark_status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['mark_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('result_status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="result_status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['result_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
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
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('examination-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
