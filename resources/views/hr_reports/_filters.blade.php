@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $periodFilter = $report === 'payroll';
@endphp
<form method="GET" action="{{ route('hr-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
    <input type="hidden" name="report" value="{{ $report }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if($show('search'))
            <label class="text-sm text-slate-700">{{ $searchLabel }}
                <input class="input mt-1" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="{{ $searchPlaceholder }}">
            </label>
        @endif
        @if($show('faculty_id'))
            <label class="text-sm text-slate-700">Staff / Employee
                <select class="input mt-1" name="faculty_id">
                    <option value="">All staff</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}" @selected((string) $filters['faculty_id'] === (string) $employee->id)>{{ $employee->full_name }} ({{ $employee->employee_code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('department_id'))
            <label class="text-sm text-slate-700">Department
                <select class="input mt-1" name="department_id">
                    <option value="">All departments</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) $filters['department_id'] === (string) $department->id)>{{ $department->name }}@if($department->code) ({{ $department->code }})@endif</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('designation_id'))
            <label class="text-sm text-slate-700">Designation
                <select class="input mt-1" name="designation_id">
                    <option value="">All designations</option>
                    @foreach($designations as $designation)
                        <option value="{{ $designation->id }}" @selected((string) $filters['designation_id'] === (string) $designation->id)>{{ $designation->name }}@if($designation->code) ({{ $designation->code }})@endif</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('employment_type'))
            <label class="text-sm text-slate-700">Employment type
                <select class="input mt-1" name="employment_type">
                    <option value="">All employment types</option>
                    @foreach($employmentTypes as $type)
                        <option value="{{ $type }}" @selected($filters['employment_type'] === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('staff_status'))
            <label class="text-sm text-slate-700">Staff status
                <select class="input mt-1" name="staff_status">
                    <option value="">All staff statuses</option>
                    @foreach($staffStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['staff_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('document_type_id'))
            <label class="text-sm text-slate-700">Document type
                <select class="input mt-1" name="document_type_id">
                    <option value="">All document types</option>
                    @foreach($documentTypes as $type)
                        <option value="{{ $type->id }}" @selected((string) $filters['document_type_id'] === (string) $type->id)>{{ $type->name }}@if($type->code) ({{ $type->code }})@endif</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('document_status'))
            <label class="text-sm text-slate-700">Document status
                <select class="input mt-1" name="document_status">
                    <option value="">All document statuses</option>
                    @foreach($documentStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['document_status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('leave_type_id'))
            <label class="text-sm text-slate-700">Leave type
                <select class="input mt-1" name="leave_type_id">
                    <option value="">All leave types</option>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}" @selected((string) $filters['leave_type_id'] === (string) $type->id)>{{ $type->name }}@if($type->code) ({{ $type->code }})@endif</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('salary_structure_id'))
            <label class="text-sm text-slate-700">Salary structure
                <select class="input mt-1" name="salary_structure_id">
                    <option value="">All salary structures</option>
                    @foreach($salaryStructures as $structure)
                        <option value="{{ $structure->id }}" @selected((string) $filters['salary_structure_id'] === (string) $structure->id)>{{ $structure->name }} ({{ $structure->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('from'))
            <label class="text-sm text-slate-700">{{ $dateLabel }} from
                <input class="input mt-1" type="{{ $periodFilter ? 'month' : 'date' }}" name="from" value="{{ $filters['from'] }}">
            </label>
            <label class="text-sm text-slate-700">{{ $dateLabel }} to
                <input class="input mt-1" type="{{ $periodFilter ? 'month' : 'date' }}" name="to" value="{{ $filters['to'] }}">
            </label>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="button">Apply filters</button>
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('hr-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
