{{--
    Book copy form (create + edit).

    The title is chosen only on create. XSS safety: every value is echoed
    through Blade's {{ }} escaping.
--}}
@if(isset($copy))
    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm font-semibold text-slate-900">{{ $copy->book?->title ?? 'Book' }}</p>
        <p class="mt-1 text-xs text-slate-600">Catalogue code <span class="font-mono">{{ $copy->book?->code ?? '—' }}</span>. The title of an existing copy cannot be changed.</p>
    </div>
@else
    <div class="sm:col-span-2">
        <label class="label" for="book_id">Book</label>
        <select class="input" id="book_id" name="book_id" required>
            <option value="">Select a title</option>
            @foreach($books as $book)
                <option value="{{ $book->id }}" data-next-copy="{{ $nextCopyNumbers[$book->id] ?? 1 }}" @selected((int) old('book_id', $selectedBookId ?? 0) === $book->id)>
                    {{ $book->title }} ({{ $book->code }})@if($book->status !== 'active') · inactive @endif
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Copies belong to a title already catalogued for this college. Book master data is not copied here.</p>
        <p class="mt-1 text-xs text-rose-600">@error('book_id'){{ $message }}@enderror</p>
    </div>
@endif
<div>
    <label class="label" for="accession_number">Accession number</label>
    <input class="input" id="accession_number" name="accession_number" type="text" value="{{ old('accession_number', $copy->accession_number ?? '') }}" required maxlength="50" placeholder="e.g. ACC-0001">
    <p class="mt-1 text-xs text-slate-500">Unique among this college's active copies. Stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('accession_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="barcode">Barcode</label>
    <input class="input" id="barcode" name="barcode" type="text" value="{{ old('barcode', $copy->barcode ?? '') }}" maxlength="64" placeholder="Optional">
    <p class="mt-1 text-xs text-slate-500">Optional. Unique within the college when present.</p>
    <p class="mt-1 text-xs text-rose-600">@error('barcode'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="copy_number">Copy number</label>
    <input class="input" id="copy_number" name="copy_number" type="number" min="1" max="100000" required value="{{ old('copy_number', $copy->copy_number ?? '') }}" @if(old('copy_number')) data-touched="1" @endif>
    <p class="mt-1 text-xs text-slate-500">Unique among this title's active copies. Choosing a title suggests the next number.</p>
    <p class="mt-1 text-xs text-rose-600">@error('copy_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="location">Location</label>
    <input class="input" id="location" name="location" type="text" value="{{ old('location', $copy->location ?? '') }}" maxlength="255" placeholder="e.g. Stack A / Shelf 3">
    <p class="mt-1 text-xs text-rose-600">@error('location'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="condition">Condition</label>
    <select class="input" id="condition" name="condition" required>
        @foreach($conditions as $condition)
            <option value="{{ $condition }}" @selected(old('condition', $copy->condition ?? 'good') === $condition)>{{ ucfirst($condition) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('condition'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="acquired_on">Acquired on</label>
    <input class="input" id="acquired_on" name="acquired_on" type="date" value="{{ old('acquired_on', isset($copy) && $copy->acquired_on ? $copy->acquired_on->format('Y-m-d') : '') }}">
    <p class="mt-1 text-xs text-rose-600">@error('acquired_on'){{ $message }}@enderror</p>
</div>
@if(isset($copy) && $copy->status === 'issued')
    <div class="sm:col-span-2 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
        This copy is issued. Return it or mark it lost from Issue / Return before changing its status. Other details can still be corrected.
    </div>
@else
    <div>
        <label class="label" for="status">Status</label>
        <select class="input" id="status" name="status" required>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $copy->status ?? 'available') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Issued is set only when the copy is lent to a member.</p>
        <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
    </div>
@endif
<div class="sm:col-span-2">
    <label class="label" for="remarks">Remarks</label>
    <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $copy->remarks ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
</div>
