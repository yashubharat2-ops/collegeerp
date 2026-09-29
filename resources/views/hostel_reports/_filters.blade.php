<form method="GET" action="{{ route('hostel-reports.index') }}" class="no-print mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <input type="hidden" name="report" value="{{ $report }}">

    @if(in_array('academic_year_id', $visible, true))
        <div>
            <label class="label" for="academic_year_id">Academic year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($filterOptions['years'] as $year)
                    <option value="{{ $year->id }}" @selected((string) ($filters['academic_year_id'] ?? '') === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('academic_term_id', $visible, true))
        <div>
            <label class="label" for="academic_term_id">Academic term</label>
            <select class="input" id="academic_term_id" name="academic_term_id">
                <option value="">All terms</option>
                @foreach($filterOptions['terms'] as $term)
                    <option value="{{ $term->id }}" @selected((string) ($filters['academic_term_id'] ?? '') === (string) $term->id)>{{ $term->academicYear?->name ?? '—' }} — {{ $term->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('hostel_id', $visible, true))
        <div>
            <label class="label" for="hostel_id">Hostel</label>
            <select class="input" id="hostel_id" name="hostel_id">
                <option value="">All hostels</option>
                @foreach($filterOptions['hostels'] as $hostel)
                    <option value="{{ $hostel->id }}" @selected((string) ($filters['hostel_id'] ?? '') === (string) $hostel->id)>{{ $hostel->name }} ({{ $hostel->code }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('hostel_building_id', $visible, true))
        <div>
            <label class="label" for="hostel_building_id">Building / block</label>
            <select class="input" id="hostel_building_id" name="hostel_building_id">
                <option value="">All buildings / blocks</option>
                @foreach($filterOptions['buildings'] as $building)
                    <option value="{{ $building->id }}" @selected((string) ($filters['hostel_building_id'] ?? '') === (string) $building->id)>{{ $building->name }} ({{ $building->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('hostel_room_id', $visible, true))
        <div>
            <label class="label" for="hostel_room_id">Room</label>
            <select class="input" id="hostel_room_id" name="hostel_room_id">
                <option value="">All rooms</option>
                @foreach($filterOptions['rooms'] as $room)
                    <option value="{{ $room->id }}" @selected((string) ($filters['hostel_room_id'] ?? '') === (string) $room->id)>{{ $room->room_number }} — {{ $room->building?->name ?? '—' }} ({{ $room->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('hostel_bed_id', $visible, true))
        <div>
            <label class="label" for="hostel_bed_id">Bed</label>
            <select class="input" id="hostel_bed_id" name="hostel_bed_id">
                <option value="">All beds</option>
                @foreach($filterOptions['beds'] as $bed)
                    <option value="{{ $bed->id }}" @selected((string) ($filters['hostel_bed_id'] ?? '') === (string) $bed->id)>Bed {{ $bed->bed_number }} — {{ $bed->room?->room_number ?? '—' }} ({{ $bed->hostel?->name ?? '—' }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('student_id', $visible, true))
        <div>
            <label class="label" for="student_id">Student / resident</label>
            <select class="input" id="student_id" name="student_id">
                <option value="">All students</option>
                @foreach($filterOptions['students'] as $student)
                    <option value="{{ $student->id }}" @selected((string) ($filters['student_id'] ?? '') === (string) $student->id)>{{ $student->student_number }} — {{ $student->fullName() }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('program_id', $visible, true))
        <div>
            <label class="label" for="program_id">Class / program</label>
            <select class="input" id="program_id" name="program_id">
                <option value="">All classes / programs</option>
                @foreach($filterOptions['programs'] as $program)
                    <option value="{{ $program->id }}" @selected((string) ($filters['program_id'] ?? '') === (string) $program->id)>{{ $program->name }}{{ $program->code ? ' ('.$program->code.')' : '' }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('section_id', $visible, true))
        <div>
            <label class="label" for="section_id">Section</label>
            <select class="input" id="section_id" name="section_id">
                <option value="">All sections</option>
                @foreach($filterOptions['sections'] as $section)
                    <option value="{{ $section->id }}" @selected((string) ($filters['section_id'] ?? '') === (string) $section->id)>{{ $section->name }}{{ $section->program?->name ? ' — '.$section->program->name : '' }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @foreach($statusOptions as $key => $choices)
        <div>
            <label class="label" for="{{ $key }}">{{ match($key) { 'allocation_status' => 'Allocation status', 'attendance_status' => 'Attendance status', 'fee_status' => 'Fee ledger status', default => 'Status' } }}</label>
            <select class="input" id="{{ $key }}" name="{{ $key }}">
                <option value="">All statuses</option>
                @foreach($choices as $choice)
                    <option value="{{ $choice }}" @selected(($filters[$key] ?? '') === $choice)>{{ ucfirst($choice) }}</option>
                @endforeach
            </select>
        </div>
    @endforeach

    @if(in_array('from', $visible, true))
        <div>
            <label class="label" for="from">{{ $dateLabel }} from</label>
            <input class="input" id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div>
            <label class="label" for="to">{{ $dateLabel }} to</label>
            <input class="input" id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
        </div>
    @endif

    <div class="flex items-end gap-2 lg:col-span-4">
        <button class="button" type="submit">Run report</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-reports.index', ['report' => $report]) }}">Reset</a>
    </div>
</form>
