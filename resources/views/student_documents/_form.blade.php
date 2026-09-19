@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Document</h3>
    <p class="mt-1 text-xs text-slate-500">Files are stored on a private disk under a server-generated name and path. The stored location is never taken from the browser.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(!isset($document))
            <div>
                <label class="label" for="student_id">Student *</label>
                <select class="input" id="student_id" name="student_id" required>
                    <option value="">— Select student —</option>
                    @foreach($students as $s)
                        <option value="{{ $s->id }}" @selected((int) old('student_id', $selectedStudentId ?? 0) === $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('student_id'){{ $message }}@enderror</p>
            </div>
        @else
            <div class="md:col-span-2">
                <p class="text-sm"><span class="font-semibold">Student:</span> {{ $document->student?->fullName() }} ({{ $document->student?->student_number }})</p>
                <p class="text-sm"><span class="font-semibold">Current file:</span> {{ $document->original_filename }} ({{ $document->sizeInKb() }} KB)</p>
                <p class="text-sm"><span class="font-semibold">Verification:</span> {{ ucfirst($document->verification_status) }}@if($document->verifiedBy) · by {{ $document->verifiedBy->name }}@endif</p>
            </div>
        @endif

        <div>
            <label class="label" for="title">Document title *</label>
            <input class="input" type="text" id="title" name="title" maxlength="255" required
                   value="{{ old('title', $document->title ?? '') }}" placeholder="e.g. Transfer Certificate copy">
            <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="document_type_id">Document type</label>
            <select class="input" id="document_type_id" name="document_type_id">
                <option value="">— No type selected —</option>
                @foreach($documentTypes as $type)
                    <option value="{{ $type->id }}" @selected((int) old('document_type_id', $document->document_type_id ?? 0) === $type->id)>{{ $type->name }} ({{ $type->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('document_type_id'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">Types come from the shared document-type master data; selecting one applies its allowed formats and size limit.</p>
        </div>

        <div>
            <label class="label" for="issue_date">Issue date</label>
            <input class="input" type="date" id="issue_date" name="issue_date" value="{{ old('issue_date', isset($document) ? $document->issue_date?->format('Y-m-d') : '') }}">
            <p class="mt-1 text-xs text-rose-600">@error('issue_date'){{ $message }}@enderror</p>
        </div>

        <div>
            <label class="label" for="expiry_date">Expiry date</label>
            <input class="input" type="date" id="expiry_date" name="expiry_date" value="{{ old('expiry_date', isset($document) ? $document->expiry_date?->format('Y-m-d') : '') }}">
            <p class="mt-1 text-xs text-rose-600">@error('expiry_date'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2">
            <label class="label" for="file">File {{ isset($document) ? '(leave empty to keep the current file)' : '*' }}</label>
            <input class="input" type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png" {{ isset($document) ? '' : 'required' }}>
            <p class="mt-1 text-xs text-rose-600">@error('file'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">PDF, JPG or PNG only, maximum 5 MB. Uploading a replacement resets verification to pending.</p>
        </div>

        <div class="md:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $document->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-documents.index') }}">Cancel</a>
        </div>
    </div>
</div>
