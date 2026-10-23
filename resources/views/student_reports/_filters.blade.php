@php
    $dateLabel = match ($report) {
        'admissions' => 'Admission date',
        'enrollments', 'strength', 'new_old' => 'Enrollment date',
        'documents' => 'Document upload date',
        'promotions' => 'Promotion request date',
        'transfers' => 'Transfer date',
        default => 'Student admission date',
    };
@endphp

<div class="no-print">
    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('student-reports.index')"
        :reset-url="route('student-reports.index', ['report' => $report])"
        :preserve="['report' => $report]"
        title="Filter Student Report"
    >
        <div class="erp-list-field erp-list-filter-span-2">
            <label for="filter-report-search" class="mb-1 block text-xs font-semibold text-slate-700">Search student / reference</label>
            <input id="filter-report-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Number or name">
        </div>

        @if($report === 'admissions')
            <x-list.filter-select name="admission_status" label="Admission status" :value="$filters['admission_status']" placeholder="All admission statuses">
                @foreach(\App\Models\Admission::STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['admission_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @elseif($report === 'promotions')
            <x-list.filter-select name="promotion_status" label="Promotion status" :value="$filters['promotion_status']" placeholder="All promotion statuses">
                @foreach(\App\Models\StudentPromotion::STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['promotion_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @elseif($report === 'transfers')
            <x-list.filter-select name="transfer_status" label="Transfer request status" :value="$filters['transfer_status']" placeholder="All request statuses">
                @foreach(\App\Models\StudentTransfer::STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['transfer_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
            <x-list.filter-select name="tc_status" label="TC status" :value="$filters['tc_status']" placeholder="All TC statuses">
                @foreach(\App\Models\StudentTransfer::TC_STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['tc_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>
        @elseif($report === 'documents')
            <x-list.filter-select name="document_status" label="Document status" :value="$filters['document_status']" placeholder="All students">
                @foreach(\App\Models\StudentDocument::VERIFICATION_STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['document_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
                <option value="none" @selected($filters['document_status'] === 'none')>No documents</option>
            </x-list.filter-select>
        @elseif($report === 'new_old')
            <x-list.filter-select name="entry_type" label="Student type" :value="$filters['entry_type']" placeholder="New and old">
                <option value="new" @selected($filters['entry_type'] === 'new')>New</option>
                <option value="old" @selected($filters['entry_type'] === 'old')>Old / Continuing</option>
            </x-list.filter-select>
        @elseif($report === 'demographics')
            <x-list.filter-select name="gender" label="Gender" :value="$filters['gender']" placeholder="All genders">
                @foreach(['male', 'female', 'other', 'prefer_not_to_say'] as $gender)
                    <option value="{{ $gender }}" @selected($filters['gender'] === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                @endforeach
                <option value="not_recorded" @selected($filters['gender'] === 'not_recorded')>Not recorded</option>
            </x-list.filter-select>
        @endif

        <div class="erp-list-field">
            <label for="filter-report-from" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} from</label>
            <input id="filter-report-from" class="input !py-2 !text-xs" type="date" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-list-field">
            <label for="filter-report-to" class="mb-1 block text-xs font-semibold text-slate-700">{{ $dateLabel }} to</label>
            <input id="filter-report-to" class="input !py-2 !text-xs" type="date" name="to" value="{{ $filters['to'] }}">
        </div>
    </x-student-filter>

    @if($report === 'promotions')
        <p class="mt-2 text-xs text-slate-500">Academic year and Program/Class describe the promotion target. Semester/Section matches the target section, including pending promotions.</p>
    @elseif($report === 'admissions')
        <p class="mt-2 text-xs text-slate-500">Academic year and Program/Class belong to the admission. Student identity and enrollment-history filters apply to converted students only.</p>
    @endif
</div>
