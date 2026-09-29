@if(!empty($activeFilters))
<form method="GET" action="{{ route('certificate-reports.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <input type="hidden" name="report" value="{{ $report }}">

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if(in_array('search', $activeFilters, true))
        <div>
            <label for="filter-search" class="block text-xs font-semibold text-slate-700">Search</label>
            <input id="filter-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Certificate #, student, enrollment, purpose…" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('certificate_type_id', $activeFilters, true))
        <div>
            <label for="filter-certificate-type" class="block text-xs font-semibold text-slate-700">Certificate Type</label>
            <select id="filter-certificate-type" name="certificate_type_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Certificate Types</option>
                @foreach($certificateTypes as $typeOption)
                    <option value="{{ $typeOption->id }}" @selected(($filters['certificate_type_id'] ?? null) === $typeOption->id)>
                        {{ $typeOption->name }} ({{ $typeOption->code }})
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('status', $activeFilters, true) && !empty($statusOptions))
        <div>
            <label for="filter-status" class="block text-xs font-semibold text-slate-700">Request Status</label>
            <select id="filter-status" name="status" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Statuses</option>
                @foreach($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('verification_status', $activeFilters, true) && !empty($verificationStatusOptions))
        <div>
            <label for="filter-verification-status" class="block text-xs font-semibold text-slate-700">Verification Status</label>
            <select id="filter-verification-status" name="verification_status" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Verification Statuses</option>
                @foreach($verificationStatusOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['verification_status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('student_id', $activeFilters, true))
        <div>
            <label for="filter-student" class="block text-xs font-semibold text-slate-700">Student</label>
            <select id="filter-student" name="student_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Students</option>
                @foreach($students as $studentOption)
                    <option value="{{ $studentOption->id }}" @selected(($filters['student_id'] ?? null) === $studentOption->id)>
                        {{ $studentOption->fullName() }} ({{ $studentOption->student_number }})
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('academic_year_id', $activeFilters, true))
        <div>
            <label for="filter-academic-year" class="block text-xs font-semibold text-slate-700">Academic Year</label>
            <select id="filter-academic-year" name="academic_year_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Academic Years</option>
                @foreach($academicYears as $yearOption)
                    <option value="{{ $yearOption->id }}" @selected(($filters['academic_year_id'] ?? null) === $yearOption->id)>
                        {{ $yearOption->name }}{{ $yearOption->code ? ' (' . $yearOption->code . ')' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('program_id', $activeFilters, true))
        <div>
            <label for="filter-program" class="block text-xs font-semibold text-slate-700">Program</label>
            <select id="filter-program" name="program_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Programs</option>
                @foreach($programs as $programOption)
                    <option value="{{ $programOption->id }}" @selected(($filters['program_id'] ?? null) === $programOption->id)>
                        {{ $programOption->name }}{{ $programOption->code ? ' (' . $programOption->code . ')' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('from', $activeFilters, true))
        <div>
            <label for="filter-from" class="block text-xs font-semibold text-slate-700">From Date</label>
            <input id="filter-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('to', $activeFilters, true))
        <div>
            <label for="filter-to" class="block text-xs font-semibold text-slate-700">To Date</label>
            <input id="filter-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Apply Filters</button>
        <a href="{{ route('certificate-reports.index', ['report' => $report]) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Reset</a>
    </div>
</form>
@endif
