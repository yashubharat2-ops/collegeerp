@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $dateLabel = $report === 'subject_enrollments' ? 'Subject enrollment date' : 'Attendance date';
@endphp

<div class="no-print">
    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('academic-reports.index')"
        :reset-url="route('academic-reports.index', ['report' => $report])"
        :preserve="['report' => $report]"
        title="Filter Students in Academic Report"
    >
        @if($show('search'))
            <div class="erp-list-field erp-list-filter-span-2">
                <label for="filter-academic-report-search" class="mb-1 block text-xs font-semibold text-slate-700">Search student</label>
                <input id="filter-academic-report-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Student number or name">
            </div>
        @endif
        @if($show('subject_id'))
            <x-list.filter-select name="subject_id" label="Subject" :value="$filters['subject_id']" placeholder="All subjects">
                @foreach($subjects as $subject)
                    <option value="{{ $subject->id }}" @selected((string) $filters['subject_id'] === (string) $subject->id)>{{ $subject->name }} ({{ $subject->code }})</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('faculty_id'))
            <x-list.filter-select name="faculty_id" label="Faculty" :value="$filters['faculty_id']" placeholder="All faculty">
                @foreach($faculties as $faculty)
                    <option value="{{ $faculty->id }}" @selected((string) $filters['faculty_id'] === (string) $faculty->id)>{{ $faculty->full_name }} ({{ $faculty->employee_code }})</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('status'))
            <x-list.filter-select name="status" label="Subject enrollment status" :value="$filters['status_choice']" placeholder="All subject enrollment statuses">
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status_choice'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('attendance_status'))
            <x-list.filter-select name="attendance_status" label="Attendance status" :value="$filters['attendance_status']" placeholder="All attendance statuses">
                @foreach(\App\Domain\Academic\Services\AcademicReportService::ATTENDANCE_STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['attendance_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('group'))
            <x-list.filter-select name="group" label="Summarise by" :value="$filters['group']" :placeholder="null">
                <option value="subject" @selected($filters['group'] === 'subject')>Student + subject + term</option>
                <option value="student" @selected($filters['group'] === 'student')>Student (all subjects)</option>
            </x-list.filter-select>
        @endif
        @if($show('below'))
            <div class="erp-list-field">
                <label for="filter-attendance-below" class="mb-1 block text-xs font-semibold text-slate-700">Attendance below (%)</label>
                <input id="filter-attendance-below" class="input !py-2 !text-xs" type="number" name="below" min="0" max="100" step="0.01" value="{{ $filters['below'] }}" placeholder="e.g. 75">
            </div>
        @endif
        @if($show('from'))
            <div class="erp-list-field">
                <label for="filter-academic-report-from" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} from</label>
                <input id="filter-academic-report-from" class="input !py-2 !text-xs" type="date" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="erp-list-field">
                <label for="filter-academic-report-to" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} to</label>
                <input id="filter-academic-report-to" class="input !py-2 !text-xs" type="date" name="to" value="{{ $filters['to'] }}">
            </div>
        @endif
    </x-student-filter>
</div>
