@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $feeTypeLabel = fn (string $type): string => match ($type) {
        'tuition' => 'Tuition / student fees',
        'transport' => 'Transport fee',
        'hostel' => 'Hostel fee',
        default => ucfirst($type),
    };
@endphp
<form method="GET" action="{{ route('finance-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
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
        @if($show('student_id'))
            <label class="text-sm text-slate-700">Student
                <select class="input mt-1" name="student_id">
                    <option value="">All students</option>
                    @foreach($students as $student)
                        <option value="{{ $student->id }}" @selected((string) $filters['student_id'] === (string) $student->id)>{{ $student->student_number }} · {{ $student->fullName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('fee_structure_id'))
            <label class="text-sm text-slate-700">Fee structure
                <select class="input mt-1" name="fee_structure_id">
                    <option value="">All fee structures</option>
                    @foreach($feeStructures as $structure)
                        <option value="{{ $structure->id }}" @selected((string) $filters['fee_structure_id'] === (string) $structure->id)>{{ $structure->name }} ({{ $structure->code }}) · {{ $structure->academicYear?->name ?? 'No year' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('fee_category_id'))
            <label class="text-sm text-slate-700">Fee category
                <select class="input mt-1" name="fee_category_id">
                    <option value="">All fee categories</option>
                    @foreach($feeCategories as $category)
                        <option value="{{ $category->id }}" @selected((string) $filters['fee_category_id'] === (string) $category->id)>{{ $category->name }} ({{ $category->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('type'))
            <label class="text-sm text-slate-700">Discount type
                <select class="input mt-1" name="type">
                    <option value="">All types</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status') && $statusKey !== null)
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('fee_type'))
            <label class="text-sm text-slate-700">Fee type
                <select class="input mt-1" name="fee_type">
                    <option value="">All fee types</option>
                    @foreach($feeTypes as $type)
                        <option value="{{ $type }}" @selected($filters['fee_type'] === $type)>{{ $feeTypeLabel($type) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('payment_mode'))
            <label class="text-sm text-slate-700">Payment mode
                <select class="input mt-1" name="payment_mode">
                    <option value="">All modes</option>
                    @foreach($paymentModes as $mode)
                        <option value="{{ $mode }}" @selected($filters['payment_mode'] === $mode)>{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('route_id'))
            <label class="text-sm text-slate-700">Transport route
                <select class="input mt-1" name="route_id">
                    <option value="">All routes</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected((string) $filters['route_id'] === (string) $route->id)>{{ $route->name }} ({{ $route->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('stop_id'))
            <label class="text-sm text-slate-700">Transport stop
                <select class="input mt-1" name="stop_id">
                    <option value="">All stops</option>
                    @foreach($stops as $stop)
                        <option value="{{ $stop->id }}" @selected((string) $filters['stop_id'] === (string) $stop->id)>{{ $stop->name }} ({{ $stop->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('hostel_id'))
            <label class="text-sm text-slate-700">Hostel
                <select class="input mt-1" name="hostel_id">
                    <option value="">All hostels</option>
                    @foreach($hostels as $hostel)
                        <option value="{{ $hostel->id }}" @selected((string) $filters['hostel_id'] === (string) $hostel->id)>{{ $hostel->name }} ({{ $hostel->code }})</option>
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
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('finance-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
