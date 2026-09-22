@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Document</h3>
    <p class="mt-1 text-xs text-slate-500">Files are stored on a private disk under a server-generated name and path. The stored location is never taken from the browser.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(! isset($document))
            <div>
                <label class="label" for="vehicle_id">Vehicle *</label>
                <select class="input" id="vehicle_id" name="vehicle_id" required>
                    <option value="">— Select vehicle —</option>
                    @foreach($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected((int) old('vehicle_id', $selectedVehicleId ?? 0) === (int) $vehicle->id)>{{ $vehicle->registration_number }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('vehicle_id'){{ $message }}@enderror</p>
            </div>
        @else
            <div class="md:col-span-2">
                <p class="text-sm"><span class="font-semibold">Vehicle:</span> {{ $document->vehicle?->registration_number ?? '—' }}</p>
                <p class="text-sm"><span class="font-semibold">Current file:</span> {{ $document->original_filename }} ({{ $document->sizeInKb() }} KB)</p>
                <p class="mt-1 text-xs text-slate-500">A document stays with its vehicle for life — that keeps the file path and the audit history coherent. To move a document to another vehicle, upload it there.</p>
                {{-- The vehicle is immutable; the hidden field keeps the required
                     request rule satisfied without offering a silent re-point. --}}
                <input type="hidden" name="vehicle_id" value="{{ $document->vehicle_id }}">
            </div>
        @endif

        <div>
            <label class="label" for="document_type">Document type *</label>
            <input class="input" type="text" id="document_type" name="document_type" maxlength="100" list="vehicle-document-types" required
                   value="{{ old('document_type', $document->document_type ?? '') }}" placeholder="e.g. insurance">
            <datalist id="vehicle-document-types">
                @foreach(App\Models\VehicleDocument::TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </datalist>
            <p class="mt-1 text-xs text-rose-600">@error('document_type'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">Suggested: registration / insurance / fitness_certificate / permit / puc / other. Any other category is accepted — the list is a suggestion, not a closed set.</p>
        </div>

        <div>
            <label class="label" for="document_number">Document number</label>
            <input class="input" type="text" id="document_number" name="document_number" maxlength="100"
                   value="{{ old('document_number', $document->document_number ?? '') }}" placeholder="Policy / certificate number">
            <p class="mt-1 text-xs text-rose-600">@error('document_number'){{ $message }}@enderror</p>
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
            <p class="mt-1 text-xs text-slate-500">Validity status (active / expiring / expired) is derived from this date.</p>
        </div>

        <div class="md:col-span-2">
            <label class="label" for="file">File {{ isset($document) ? '(leave empty to keep the current file)' : '*' }}</label>
            <input class="input" type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png" {{ isset($document) ? '' : 'required' }}>
            <p class="mt-1 text-xs text-rose-600">@error('file'){{ $message }}@enderror</p>
            <p class="mt-1 text-xs text-slate-500">PDF, JPG or PNG only, maximum 5 MB. Replacing the file keeps the old path in the audit history.</p>
        </div>

        <div class="md:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $document->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>

        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('vehicle-documents.index') }}">Cancel</a>
        </div>
    </div>
</div>
