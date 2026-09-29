@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $statusText = fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
@endphp
<form method="GET" action="{{ route('transport-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
    <input type="hidden" name="report" value="{{ $report }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if($show('academic_year_id'))
            <label class="text-sm text-slate-700">Academic year
                <select class="input mt-1" name="academic_year_id">
                    <option value="">All academic years</option>
                    @foreach($years as $year)
                        <option value="{{ $year->id }}" @selected((string) $filters['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
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
        @if($show('program_id'))
            <label class="text-sm text-slate-700">Program / class
                <select class="input mt-1" name="program_id">
                    <option value="">All programs</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((string) $filters['program_id'] === (string) $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('section_id'))
            <label class="text-sm text-slate-700">Section
                <select class="input mt-1" name="section_id">
                    <option value="">All sections</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" @selected((string) $filters['section_id'] === (string) $section->id)>{{ $section->name }} ({{ $section->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('vehicle_id'))
            <label class="text-sm text-slate-700">Vehicle
                <select class="input mt-1" name="vehicle_id">
                    <option value="">All vehicles</option>
                    @foreach($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected((string) $filters['vehicle_id'] === (string) $vehicle->id)>{{ $vehicle->registration_number }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('vehicle_type'))
            <label class="text-sm text-slate-700">Vehicle type
                <select class="input mt-1" name="vehicle_type">
                    <option value="">All types</option>
                    @foreach($vehicleTypes as $type)
                        <option value="{{ $type }}" @selected($filters['vehicle_type'] === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('driver_id'))
            <label class="text-sm text-slate-700">Driver
                <select class="input mt-1" name="driver_id">
                    <option value="">All drivers</option>
                    @foreach($drivers as $driver)
                        <option value="{{ $driver->id }}" @selected((string) $filters['driver_id'] === (string) $driver->id)>{{ $driver->faculty?->full_name ?? 'Archived staff' }} ({{ $driver->license_number }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('route_id'))
            <label class="text-sm text-slate-700">Route
                <select class="input mt-1" name="route_id">
                    <option value="">All routes</option>
                    @foreach($routes as $route)
                        <option value="{{ $route->id }}" @selected((string) $filters['route_id'] === (string) $route->id)>{{ $route->name }} ({{ $route->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('stop_id'))
            <label class="text-sm text-slate-700">Stop
                <select class="input mt-1" name="stop_id">
                    <option value="">All stops</option>
                    @foreach($stops as $stop)
                        <option value="{{ $stop->id }}" @selected((string) $filters['stop_id'] === (string) $stop->id)>{{ $routes->firstWhere('id', $stop->route_id)?->name ?? '—' }} — {{ $stop->name }} ({{ $stop->sequence }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusText($status) }}</option>
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
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('transport-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
