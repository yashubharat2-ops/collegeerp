@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Admission Information</h3>
    <p class="text-xs text-slate-500 mt-1">Final enrollment from approved/selected application. Admission number is server-generated per college. Applicant data is single source of truth for future Student module.</p>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(!isset($admission))
        <div>
            <label class="text-sm font-semibold" for="application_id">Application *</label>
            <select class="input mt-1" id="application_id" name="application_id" required>
                <option value="">— Select application —</option>
                @foreach($applications as $app)
                    <option value="{{ $app->id }}" @selected((int) old('application_id', $selectedApplicationId ?? 0) === $app->id)>{{ $app->application_number }} — {{ $app->applicant->first_name }} {{ $app->applicant->last_name }} ({{ $app->status }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('application_id'){{ $message }}@enderror</p>
        </div>
        @else
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">Admission Number:</span> {{ $admission->admission_number }}</p>
            <p class="text-sm"><span class="font-semibold">Applicant:</span> {{ $admission->applicant->first_name }} {{ $admission->applicant->last_name }}</p>
            <p class="text-sm"><span class="font-semibold">Application:</span> {{ $admission->application->application_number }}</p>
        </div>
        @endif
        <div>
            <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
            <select class="input mt-1" id="academic_year_id" name="academic_year_id">
                <option value="">— Use application year —</option>
                @foreach($academicYears as $ay)
                    <option value="{{ $ay->id }}" @selected((int) old('academic_year_id', $admission->academic_year_id ?? 0) === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="program_id">Program</label>
            <select class="input mt-1" id="program_id" name="program_id">
                <option value="">— Use application program —</option>
                @foreach($programs as $prog)
                    <option value="{{ $prog->id }}" @selected((int) old('program_id', $admission->program_id ?? 0) === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="admission_date">Admission Date</label>
            <input class="input mt-1" type="date" id="admission_date" name="admission_date" value="{{ old('admission_date', isset($admission) ? $admission->admission_date?->format('Y-m-d') : now()->format('Y-m-d')) }}">
            <p class="mt-1 text-xs text-rose-600">@error('admission_date'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="status">Status *</label>
            <select class="input mt-1" id="status" name="status" required>
                <option value="active" @selected(old('status', $admission->status ?? 'active') === 'active')>Active</option>
                <option value="completed" @selected(old('status', $admission->status ?? '') === 'completed')>Completed</option>
                <option value="cancelled" @selected(old('status', $admission->status ?? '') === 'cancelled')>Cancelled</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $admission->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admissions.index') }}">Cancel</a>
        </div>
    </div>
</div>
