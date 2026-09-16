@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Document Upload</h3>
    <p class="text-xs text-slate-500 mt-1">Upload private documents. Files are stored securely and never publicly accessible.</p>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(!isset($document))
        <div>
            <label class="text-sm font-semibold" for="applicant_id">Applicant *</label>
            <select class="input mt-1" id="applicant_id" name="applicant_id" required>
                <option value="">— Select applicant —</option>
                @foreach($applicants as $applicant)
                    <option value="{{ $applicant->id }}" @selected((int) old('applicant_id') === $applicant->id)>{{ $applicant->first_name }} {{ $applicant->last_name }} — {{ $applicant->email ?? '' }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('applicant_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="application_id">Application (optional)</label>
            <select class="input mt-1" id="application_id" name="application_id">
                <option value="">— No application link —</option>
                @foreach($applications as $app)
                    <option value="{{ $app->id }}" @selected((int) old('application_id') === $app->id)>{{ $app->application_number }} — {{ $app->applicant->first_name }} {{ $app->applicant->last_name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('application_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="document_type_id">Document Type *</label>
            <select class="input mt-1" id="document_type_id" name="document_type_id" required>
                <option value="">— Select type —</option>
                @foreach($documentTypes as $type)
                    <option value="{{ $type->id }}" @selected((int) old('document_type_id', $document->document_type_id ?? 0) === $type->id)>{{ $type->name }} ({{ $type->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('document_type_id'){{ $message }}@enderror</p>
        </div>
        @else
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">Applicant:</span> {{ $document->applicant->first_name }} {{ $document->applicant->last_name }}</p>
            <p class="text-sm"><span class="font-semibold">Type:</span> {{ $document->documentType->name }} ({{ $document->documentType->code }})</p>
            <p class="text-sm"><span class="font-semibold">Current file:</span> {{ $document->original_filename }} ({{ number_format($document->file_size/1024,1) }} KB)</p>
            <p class="text-sm"><span class="font-semibold">Status:</span> {{ ucfirst($document->verification_status) }}</p>
        </div>
        @endif
        <div>
            <label class="text-sm font-semibold" for="file">File {{ isset($document) ? '(leave empty to keep current)' : '*' }}</label>
            <input class="input mt-1" type="file" id="file" name="file" {{ isset($document) ? '' : 'required' }} accept=".pdf,.jpg,.jpeg,.png">
            <p class="mt-1 text-xs text-rose-600">@error('file'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $document->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-documents.index') }}">Cancel</a>
        </div>
    </div>
</div>
