<form method="GET" action="{{ route('consolidated-reports.index') }}" class="no-print mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
    <input type="hidden" name="report" value="{{ $report }}">

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if(in_array('academic_year_id', $visible, true))
        <div>
            <label for="filter-academic-year" class="block text-xs font-semibold text-slate-700">Academic year</label>
            <select id="filter-academic-year" name="academic_year_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All academic years</option>
                @foreach($academicYears as $yearOption)
                    <option value="{{ $yearOption->id }}" @selected(($filters['academic_year_id'] ?? null) === $yearOption->id)>
                        {{ $yearOption->name }}@if($yearOption->code) ({{ $yearOption->code }})@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('academic_term_id', $visible, true))
        <div>
            <label for="filter-academic-term" class="block text-xs font-semibold text-slate-700">Academic term</label>
            <select id="filter-academic-term" name="academic_term_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All academic terms</option>
                @foreach($academicTerms as $termOption)
                    <option value="{{ $termOption->id }}" @selected(($filters['academic_term_id'] ?? null) === $termOption->id)>
                        {{ $termOption->name }}@if($termOption->academicYear) · {{ $termOption->academicYear->name }}@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('program_id', $visible, true))
        <div>
            <label for="filter-program" class="block text-xs font-semibold text-slate-700">Program</label>
            <select id="filter-program" name="program_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All programs</option>
                @foreach($programs as $programOption)
                    <option value="{{ $programOption->id }}" @selected(($filters['program_id'] ?? null) === $programOption->id)>
                        {{ $programOption->name }}@if($programOption->code) ({{ $programOption->code }})@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('section_id', $visible, true))
        <div>
            <label for="filter-section" class="block text-xs font-semibold text-slate-700">Section / batch</label>
            <select id="filter-section" name="section_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All sections</option>
                @foreach($sectionOptions as $sectionOption)
                    <option value="{{ $sectionOption->id }}" @selected(($filters['section_id'] ?? null) === $sectionOption->id)>
                        {{ $sectionOption->name }}@if($sectionOption->program) · {{ $sectionOption->program->name }}@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('examination_id', $visible, true))
        <div>
            <label for="filter-examination" class="block text-xs font-semibold text-slate-700">Examination</label>
            <select id="filter-examination" name="examination_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All examinations</option>
                @foreach($examinationOptions as $examinationOption)
                    <option value="{{ $examinationOption->id }}" @selected(($filters['examination_id'] ?? null) === $examinationOption->id)>
                        {{ $examinationOption->name }}@if($examinationOption->code) ({{ $examinationOption->code }})@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('student_status', $visible, true))
        <div>
            <label for="filter-student-status" class="block text-xs font-semibold text-slate-700">Student status</label>
            <select id="filter-student-status" name="student_status" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @foreach($studentStatuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(($filters['student_status'] ?? 'active') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('enrollment_status', $visible, true))
        <div>
            <label for="filter-enrollment-status" class="block text-xs font-semibold text-slate-700">Enrollment status</label>
            <select id="filter-enrollment-status" name="enrollment_status" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @foreach($enrollmentStatuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(($filters['enrollment_status'] ?? 'active') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('department_id', $visible, true))
        <div>
            <label for="filter-department" class="block text-xs font-semibold text-slate-700">Department</label>
            <select id="filter-department" name="department_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All departments</option>
                @foreach($departments as $departmentOption)
                    <option value="{{ $departmentOption->id }}" @selected(($filters['department_id'] ?? null) === $departmentOption->id)>{{ $departmentOption->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('designation_id', $visible, true))
        <div>
            <label for="filter-designation" class="block text-xs font-semibold text-slate-700">Designation</label>
            <select id="filter-designation" name="designation_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All designations</option>
                @foreach($designations as $designationOption)
                    <option value="{{ $designationOption->id }}" @selected(($filters['designation_id'] ?? null) === $designationOption->id)>{{ $designationOption->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('certificate_type_id', $visible, true))
        <div>
            <label for="filter-certificate-type" class="block text-xs font-semibold text-slate-700">Certificate type</label>
            <select id="filter-certificate-type" name="certificate_type_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All certificate types</option>
                @foreach($certificateTypes as $typeOption)
                    <option value="{{ $typeOption->id }}" @selected(($filters['certificate_type_id'] ?? null) === $typeOption->id)>
                        {{ $typeOption->name }}@if($typeOption->code) ({{ $typeOption->code }})@endif
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('threshold', $visible, true))
        <div>
            <label for="filter-threshold" class="block text-xs font-semibold text-slate-700">Low-stock threshold</label>
            <input id="filter-threshold" type="number" step="0.01" min="0" name="threshold" value="{{ $filters['threshold'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('from', $visible, true))
        <div>
            <label for="filter-from" class="block text-xs font-semibold text-slate-700">{{ $dateLabel }} from</label>
            <input id="filter-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('to', $visible, true))
        <div>
            <label for="filter-to" class="block text-xs font-semibold text-slate-700">{{ $dateLabel }} to</label>
            <input id="filter-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Apply Filters</button>
        <a href="{{ route('consolidated-reports.index', ['report' => $report]) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-white">Reset</a>
    </div>
</form>
