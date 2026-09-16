@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Merit List Information</h3>
    <p class="text-xs text-slate-500 mt-1">Configurable foundation: no hard-coded formula. Merit score and rank are explicit fields.</p>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="code">Code *</label>
            <input class="input mt-1" id="code" name="code" value="{{ old('code', $meritList->code ?? '') }}" required maxlength="50">
            <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="name">Name *</label>
            <input class="input mt-1" id="name" name="name" value="{{ old('name', $meritList->name ?? '') }}" required maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
            <select class="input mt-1" id="academic_year_id" name="academic_year_id">
                <option value="">— All years —</option>
                @foreach($academicYears as $ay)
                    <option value="{{ $ay->id }}" @selected((int) old('academic_year_id', $meritList->academic_year_id ?? 0) === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="program_id">Program</label>
            <select class="input mt-1" id="program_id" name="program_id">
                <option value="">— All programs —</option>
                @foreach($programs as $prog)
                    <option value="{{ $prog->id }}" @selected((int) old('program_id', $meritList->program_id ?? 0) === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="description">Description</label>
            <textarea class="input mt-1" id="description" name="description" rows="2" maxlength="2000">{{ old('description', $meritList->description ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $meritList->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-merit-lists.index') }}">Cancel</a>
        </div>
    </div>
</div>
