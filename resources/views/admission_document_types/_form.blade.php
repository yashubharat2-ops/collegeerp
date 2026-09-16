@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Document Type Information</h3>
    <p class="text-xs text-slate-500 mt-1">Configure document types per college. Allowed extensions and max size control upload validation.</p>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        <div>
            <label class="text-sm font-semibold" for="code">Code *</label>
            <input class="input mt-1" id="code" name="code" value="{{ old('code', $documentType->code ?? '') }}" required maxlength="50">
            <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="name">Name *</label>
            <input class="input mt-1" id="name" name="name" value="{{ old('name', $documentType->name ?? '') }}" required maxlength="255">
            <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="description">Description</label>
            <textarea class="input mt-1" id="description" name="description" rows="2" maxlength="2000">{{ old('description', $documentType->description ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="allowed_extensions">Allowed Extensions</label>
            <input class="input mt-1" id="allowed_extensions" name="allowed_extensions" value="{{ old('allowed_extensions', $documentType->allowed_extensions ?? 'pdf,jpg,jpeg,png') }}" placeholder="pdf,jpg,jpeg,png">
            <p class="mt-1 text-xs text-rose-600">@error('allowed_extensions'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="allowed_mimes">Allowed Mimes</label>
            <input class="input mt-1" id="allowed_mimes" name="allowed_mimes" value="{{ old('allowed_mimes', $documentType->allowed_mimes ?? 'application/pdf,image/jpeg,image/png') }}" placeholder="application/pdf,image/jpeg">
            <p class="mt-1 text-xs text-rose-600">@error('allowed_mimes'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="max_size_kb">Max Size KB *</label>
            <input class="input mt-1" type="number" id="max_size_kb" name="max_size_kb" value="{{ old('max_size_kb', $documentType->max_size_kb ?? 5120) }}" required min="1" max="51200">
            <p class="mt-1 text-xs text-rose-600">@error('max_size_kb'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="status">Status *</label>
            <select class="input mt-1" id="status" name="status" required>
                <option value="active" @selected(old('status', $documentType->status ?? 'active') === 'active')>Active</option>
                <option value="inactive" @selected(old('status', $documentType->status ?? '') === 'inactive')>Inactive</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_required" name="is_required" value="1" @checked(old('is_required', $documentType->is_required ?? false))>
            <label class="text-sm font-semibold" for="is_required">Required</label>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-document-types.index') }}">Cancel</a>
        </div>
    </div>
</div>
