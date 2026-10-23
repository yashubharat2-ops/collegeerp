@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $statusLabel = match ($report) {
        'attendance' => 'Exam attendance status',
        'marks' => 'Mark entry status',
        default => 'Result status',
    };
@endphp

<div class="no-print">
    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('examination-reports.index')"
        :reset-url="route('examination-reports.index', ['report' => $report])"
        :preserve="['report' => $report]"
        title="Filter Students in Examination Report"
    >
        @if($show('search'))
            <div class="erp-list-field erp-list-filter-span-2">
                <label for="filter-examination-report-search" class="mb-1 block text-xs font-semibold text-slate-700">Search student</label>
                <input id="filter-examination-report-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Student number or name">
            </div>
        @endif
        @if($show('examination_id'))
            <x-list.filter-select name="examination_id" label="Examination" :value="$filters['examination_id']" placeholder="All examinations">
                @foreach($examinations as $examination)
                    <option value="{{ $examination->id }}" @selected((string) $filters['examination_id'] === (string) $examination->id)>{{ $examination->name }} ({{ $examination->code }})</option>
                @endforeach
            </x-list.filter-select>
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
        @if($show('grade'))
            <x-list.filter-select name="grade" label="Grade" :value="$filters['grade']" placeholder="All grades">
                @foreach($grades as $grade)
                    <option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('attendance_status'))
            <x-list.filter-select name="attendance_status" :label="$statusLabel" :value="$filters['attendance_status']" placeholder="All exam attendance statuses">
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['attendance_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('mark_status'))
            <x-list.filter-select name="mark_status" :label="$statusLabel" :value="$filters['mark_status']" placeholder="All mark entry statuses">
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['mark_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('result_status'))
            <x-list.filter-select name="result_status" :label="$statusLabel" :value="$filters['result_status']" placeholder="All result statuses">
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['result_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @endif
        @if($show('from'))
            <div class="erp-list-field">
                <label for="filter-examination-report-from" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} from</label>
                <input id="filter-examination-report-from" class="input !py-2 !text-xs" type="date" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="erp-list-field">
                <label for="filter-examination-report-to" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} to</label>
                <input id="filter-examination-report-to" class="input !py-2 !text-xs" type="date" name="to" value="{{ $filters['to'] }}">
            </div>
        @endif
    </x-student-filter>
</div>
