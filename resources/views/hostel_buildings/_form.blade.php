{{--
    Building / block form (create + edit).

    The parent hostel is chosen only on create. XSS safety: every value is
    echoed through Blade's {{ }} escaping.
--}}
@if(isset($building))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $building->hostel?->name ?? 'Hostel' }}</p>
        <p class="mt-1 text-xs text-slate-600">Hostel code <span class="font-mono">{{ $building->hostel?->code ?? '—' }}</span>. A building / block cannot be moved to another hostel.</p>
    </div>
@else
    <div class="sm:col-span-2">
        <label class="label" for="hostel_id">Hostel</label>
        <select class="input" id="hostel_id" name="hostel_id" required>
            <option value="">Select a hostel</option>
            @foreach($hostels as $hostel)
                <option value="{{ $hostel->id }}" @selected((int) old('hostel_id', $selectedHostelId ?? 0) === $hostel->id)>
                    {{ $hostel->name }} ({{ $hostel->code }})@if($hostel->status !== 'active') · inactive @endif
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">The building belongs to a hostel of the active college.</p>
        @if($hostels->isEmpty())
            <p class="mt-1 text-xs text-amber-700">No hostels exist yet for this college — @can('create', App\Models\Hostel::class)<a class="underline" href="{{ route('hostels.create') }}">add one first</a>@else ask an administrator to add one first @endcan.</p>
        @endif
        <p class="mt-1 text-xs text-rose-600">@error('hostel_id'){{ $message }}@enderror</p>
    </div>
@endif
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $building->name ?? '') }}" required maxlength="255" placeholder="e.g. North Block, Block C">
    <p class="mt-1 text-xs text-slate-500">Unique among this hostel's active buildings / blocks.</p>
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $building->code ?? '') }}" required maxlength="50" placeholder="e.g. NB, BLK-C">
    <p class="mt-1 text-xs text-slate-500">Unique within this hostel, including archived records. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="floors">Floors</label>
    <input class="input" id="floors" name="floors" type="number" min="1" max="200" value="{{ old('floors', $building->floors ?? '') }}" placeholder="Optional">
    <p class="mt-1 text-xs text-slate-500">Optional. When set, room floors are checked against it.</p>
    <p class="mt-1 text-xs text-rose-600">@error('floors'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $building->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Inactive buildings stay on record with their rooms and beds.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000" placeholder="Optional">{{ old('description', $building->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
