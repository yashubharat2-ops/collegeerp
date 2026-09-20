@csrf
<div class="mt-6 grid gap-5 md:grid-cols-2">
    <div>
        <label class="text-sm font-semibold" for="examination_id">Examination</label>
        <select class="input mt-1" id="examination_id" name="examination_id" required>
            <option value="">Select examination</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}"
                    data-year="{{ $exam->academic_year_id }}"
                    data-term="{{ $exam->academic_term_id }}"
                    @selected((int) old('examination_id', $schedule->examination_id ?? ($selectedExamId ?? 0)) === $exam->id)>
                    {{ $exam->name }} ({{ $exam->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('examination_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="subject_id">Subject</label>
        <select class="input mt-1" id="subject_id" name="subject_id" required>
            <option value="">Select subject</option>
            @foreach($subjects as $sub)
                <option value="{{ $sub->id }}" @selected((int) old('subject_id', $schedule->subject_id ?? 0) === $sub->id)>
                    {{ $sub->name }} ({{ $sub->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('subject_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
        <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
            <option value="">Select academic year</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $schedule->academic_year_id ?? 0) === $year->id)>
                    {{ $year->name }} ({{ $year->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="academic_term_id">Academic Term / Semester</label>
        <select class="input mt-1" id="academic_term_id" name="academic_term_id" required>
            <option value="">Select academic term</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" data-year="{{ $term->academic_year_id }}" @selected((int) old('academic_term_id', $schedule->academic_term_id ?? 0) === $term->id)>
                    {{ $term->name }} ({{ $term->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="program_id">Program</label>
        <select class="input mt-1" id="program_id" name="program_id" required>
            <option value="">Select program</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) old('program_id', $schedule->program_id ?? 0) === $prog->id)>
                    {{ $prog->name }} ({{ $prog->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="section_id">Section / Batch</label>
        <select class="input mt-1" id="section_id" name="section_id" required>
            <option value="">Select section</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" data-year="{{ $sec->academic_year_id }}" data-program="{{ $sec->program_id }}" @selected((int) old('section_id', $schedule->section_id ?? 0) === $sec->id)>
                    {{ $sec->name }} ({{ $sec->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('section_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="exam_date">Exam Date</label>
        <input class="input mt-1" id="exam_date" name="exam_date" type="date" value="{{ old('exam_date', isset($schedule) && $schedule->exam_date ? $schedule->exam_date->format('Y-m-d') : '') }}" required>
        <p class="mt-1 text-xs text-rose-600">@error('exam_date'){{ $message }}@enderror</p>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="text-sm font-semibold" for="start_time">Start Time</label>
            <input class="input mt-1" id="start_time" name="start_time" type="time" value="{{ old('start_time', isset($schedule) ? substr($schedule->start_time, 0, 5) : '') }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('start_time'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="end_time">End Time</label>
            <input class="input mt-1" id="end_time" name="end_time" type="time" value="{{ old('end_time', isset($schedule) ? substr($schedule->end_time, 0, 5) : '') }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('end_time'){{ $message }}@enderror</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="text-sm font-semibold" for="max_marks">Maximum Marks</label>
            <input class="input mt-1" id="max_marks" name="max_marks" type="number" step="0.5" min="1" max="10000" value="{{ old('max_marks', isset($schedule) ? (float) $schedule->max_marks : 100) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('max_marks'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="passing_marks">Passing Marks</label>
            <input class="input mt-1" id="passing_marks" name="passing_marks" type="number" step="0.5" min="0" max="10000" value="{{ old('passing_marks', isset($schedule) ? (float) $schedule->passing_marks : 40) }}" required>
            <p class="mt-1 text-xs text-rose-600">@error('passing_marks'){{ $message }}@enderror</p>
        </div>
    </div>

    <div>
        <label class="text-sm font-semibold" for="status">Status</label>
        <select class="input mt-1" id="status" name="status" required>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected(old('status', $schedule->status ?? 'scheduled') === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="faculty_id">Faculty / Invigilator (optional)</label>
        <select class="input mt-1" id="faculty_id" name="faculty_id">
            <option value="">No invigilator assigned</option>
            @foreach($faculties as $fac)
                <option value="{{ $fac->id }}" @selected((int) old('faculty_id', $schedule->faculty_id ?? 0) === $fac->id)>
                    {{ $fac->full_name }} ({{ $fac->employee_code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('faculty_id'){{ $message }}@enderror</p>
    </div>

    <div>
        <label class="text-sm font-semibold" for="campus_id">Campus (optional)</label>
        <select class="input mt-1" id="campus_id" name="campus_id">
            <option value="">College / Main Campus</option>
            @foreach($campuses as $camp)
                <option value="{{ $camp->id }}" @selected((int) old('campus_id', $schedule->campus_id ?? 0) === $camp->id)>
                    {{ $camp->name }} ({{ $camp->code }})
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-rose-600">@error('campus_id'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="room">Room / Venue (optional)</label>
        <input class="input mt-1" id="room" name="room" value="{{ old('room', $schedule->room ?? '') }}" maxlength="100" placeholder="e.g. Hall A, Room 302">
        <p class="mt-1 text-xs text-rose-600">@error('room'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2">
        <label class="text-sm font-semibold" for="remarks">Remarks (optional)</label>
        <textarea class="input mt-1" id="remarks" name="remarks" rows="3" maxlength="2000" placeholder="Special requirements, materials allowed, or instructions">{{ old('remarks', $schedule->remarks ?? '') }}</textarea>
        <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
    </div>

    <div class="md:col-span-2 flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-schedules.index') }}">Cancel</a>
    </div>
</div>
