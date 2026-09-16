@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Merit Entry</h3>
    <p class="text-xs text-slate-500 mt-1">Flexible scoring: no hard-coded formula. Rank is explicit for deterministic ordering.</p>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(!isset($entry))
        <div>
            <label class="text-sm font-semibold" for="merit_list_id">Merit List *</label>
            <select class="input mt-1" id="merit_list_id" name="merit_list_id" required>
                <option value="">— Select list —</option>
                @foreach($meritLists as $list)
                    <option value="{{ $list->id }}" @selected((int) old('merit_list_id', $selectedMeritListId ?? 0) === $list->id)>{{ $list->code }} — {{ $list->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('merit_list_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="application_id">Application *</label>
            <select class="input mt-1" id="application_id" name="application_id" required>
                <option value="">— Select application —</option>
                @foreach($applications as $app)
                    <option value="{{ $app->id }}" @selected((int) old('application_id') === $app->id)>{{ $app->application_number }} — {{ $app->applicant->first_name }} {{ $app->applicant->last_name }} ({{ $app->status }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('application_id'){{ $message }}@enderror</p>
        </div>
        @else
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">List:</span> {{ $entry->meritList->code }} — {{ $entry->meritList->name }}</p>
            <p class="text-sm"><span class="font-semibold">Application:</span> {{ $entry->application->application_number }} — {{ $entry->applicant->first_name }} {{ $entry->applicant->last_name }}</p>
        </div>
        @endif
        <div>
            <label class="text-sm font-semibold" for="merit_score">Merit Score</label>
            <input class="input mt-1" type="number" step="0.01" id="merit_score" name="merit_score" value="{{ old('merit_score', $entry->merit_score ?? '') }}" min="0" max="9999999">
            <p class="mt-1 text-xs text-rose-600">@error('merit_score'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="rank">Rank</label>
            <input class="input mt-1" type="number" id="rank" name="rank" value="{{ old('rank', $entry->rank ?? '') }}" min="1" max="100000">
            <p class="mt-1 text-xs text-rose-600">@error('rank'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="selection_status">Selection Status *</label>
            <select class="input mt-1" id="selection_status" name="selection_status" required>
                <option value="pending" @selected(old('selection_status', $entry->selection_status ?? 'pending') === 'pending')>Pending</option>
                <option value="selected" @selected(old('selection_status', $entry->selection_status ?? '') === 'selected')>Selected</option>
                <option value="waitlisted" @selected(old('selection_status', $entry->selection_status ?? '') === 'waitlisted')>Waitlisted</option>
                <option value="rejected" @selected(old('selection_status', $entry->selection_status ?? '') === 'rejected')>Rejected</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('selection_status'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $entry->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ isset($entry) ? route('admission-merit-lists.show', $entry->merit_list_id) : route('admission-merit-lists.index') }}">Cancel</a>
        </div>
    </div>
</div>
