@php
    $yearLabel = $report === 'promotions' ? 'Target academic year' : 'Academic year';
    $sectionLabel = $report === 'promotions' ? 'Target section' : 'Class / Section';
    $dateLabel = match ($report) {
        'admissions' => 'Admission date',
        'enrollments', 'strength', 'new_old' => 'Enrollment date',
        'documents' => 'Document upload date',
        'promotions' => 'Promotion request date',
        'transfers' => 'Transfer date',
        default => 'Student admission date',
    };
@endphp
<form method="GET" action="{{ route('student-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
    <input type="hidden" name="report" value="{{ $report }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-sm text-slate-700">Search student / reference
            <input class="input mt-1" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Number or name">
        </label>
        <label class="text-sm text-slate-700">{{ $yearLabel }}
            <select class="input mt-1" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((string) $filters['academic_year_id'] === (string) $year->id)>{{ $year->name }} ({{ $year->code }})</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-slate-700">Program / Course
            <select class="input mt-1" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((string) $filters['program_id'] === (string) $program->id)>{{ $program->name }} ({{ $program->code }})@if($program->department) · {{ $program->department->name }}@endif</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-slate-700">Department
            <select class="input mt-1" name="department_id">
                <option value="">All departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) $filters['department_id'] === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        @if($report !== 'admissions')
            <label class="text-sm text-slate-700">{{ $sectionLabel }}
                <select class="input mt-1" name="section_id">
                    <option value="">All sections</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" @selected((string) $filters['section_id'] === (string) $section->id)>{{ $section->name }} · {{ $section->program?->name ?? 'No program' }} · {{ $section->academicYear?->name ?? 'No year' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="text-sm text-slate-700">Student status @if($report === 'admissions')<span class="text-xs text-slate-500">(converted only)</span>@endif
            <select class="input mt-1" name="student_status">
                <option value="all" @selected(in_array($filters['student_status'], [null, 'all'], true))>All student statuses</option>
                @foreach(\App\Models\Student::STATUSES as $status)
                    <option value="{{ $status }}" @selected($filters['student_status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </label>
        @if(in_array($report, ['students', 'profile', 'enrollments', 'strength', 'new_old', 'documents', 'demographics', 'history', 'transfers'], true))
            <label class="text-sm text-slate-700">Enrollment status
                <select class="input mt-1" name="enrollment_status">
                    <option value="all" @selected(in_array($filters['enrollment_status'], [null, 'all'], true))>All enrollment statuses</option>
                    @foreach(\App\Models\StudentEnrollment::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['enrollment_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($report === 'admissions')
            <label class="text-sm text-slate-700">Admission status
                <select class="input mt-1" name="admission_status">
                    <option value="">All admission statuses</option>
                    @foreach(\App\Models\Admission::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['admission_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @elseif($report === 'promotions')
            <label class="text-sm text-slate-700">Promotion status
                <select class="input mt-1" name="promotion_status">
                    <option value="">All promotion statuses</option>
                    @foreach(\App\Models\StudentPromotion::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['promotion_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @elseif($report === 'transfers')
            <label class="text-sm text-slate-700">Transfer request status
                <select class="input mt-1" name="transfer_status">
                    <option value="">All request statuses</option>
                    @foreach(\App\Models\StudentTransfer::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['transfer_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-700">TC status
                <select class="input mt-1" name="tc_status">
                    <option value="">All TC statuses</option>
                    @foreach(\App\Models\StudentTransfer::TC_STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['tc_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @elseif($report === 'documents')
            <label class="text-sm text-slate-700">Document status
                <select class="input mt-1" name="document_status">
                    <option value="">All students</option>
                    @foreach(\App\Models\StudentDocument::VERIFICATION_STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['document_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                    <option value="none" @selected($filters['document_status'] === 'none')>No documents</option>
                </select>
            </label>
        @elseif($report === 'new_old')
            <label class="text-sm text-slate-700">Student type
                <select class="input mt-1" name="entry_type">
                    <option value="">New and old</option>
                    <option value="new" @selected($filters['entry_type'] === 'new')>New</option>
                    <option value="old" @selected($filters['entry_type'] === 'old')>Old / Continuing</option>
                </select>
            </label>
        @elseif($report === 'demographics')
            <label class="text-sm text-slate-700">Gender
                <select class="input mt-1" name="gender">
                    <option value="">All genders</option>
                    @foreach(['male', 'female', 'other', 'prefer_not_to_say'] as $gender)
                        <option value="{{ $gender }}" @selected($filters['gender'] === $gender)>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                    @endforeach
                    <option value="not_recorded" @selected($filters['gender'] === 'not_recorded')>Not recorded</option>
                </select>
            </label>
        @endif
        <label class="text-sm text-slate-700">{{ $dateLabel }} from
            <input class="input mt-1" type="date" name="from" value="{{ $filters['from'] }}">
        </label>
        <label class="text-sm text-slate-700">{{ $dateLabel }} to
            <input class="input mt-1" type="date" name="to" value="{{ $filters['to'] }}">
        </label>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="button">Apply filters</button>
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
