<div class="grid gap-4 md:grid-cols-2">
    <div><label class="label" for="name">Role name</label><input class="input" id="name" name="name" value="{{ old('name', $role->name) }}" maxlength="255" required>@error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
    <div><label class="label" for="slug">Stable identifier</label>@if($role->exists)<input class="input bg-slate-50" id="slug" value="{{ $role->slug }}" readonly aria-describedby="slug-note">@else<input class="input" id="slug" name="slug" value="{{ old('slug') }}" maxlength="100" pattern="[a-z][a-z0-9_-]*" placeholder="e.g. academic-office" required>@endif<p class="mt-1 text-xs text-slate-500" id="slug-note">Unique in this college. Existing identifiers cannot be changed.</p>@error('slug')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
    <div class="md:col-span-2"><label class="label" for="description">Description</label><textarea class="input" id="description" name="description" maxlength="1000" rows="3">{{ old('description', $role->description) }}</textarea></div>
</div>
<div class="mt-4"><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active))> Role active</label><p class="mt-1 text-xs text-slate-500">Inactive roles grant no module permissions. Memberships and assignment history are not deleted.</p></div>
<fieldset class="mt-8" id="permissions"><legend class="panel-title">Permission assignments</legend><p class="panel-subtitle">Existing active registry entries only, grouped by module. You may only assign permissions you hold in the active college. Uncheck every entry to clear all grants.</p>
    @if(($inactivePermissions ?? 0) > 0)<p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">This role has {{ $inactivePermissions }} inactive registry assignments. Saving this permission selection removes those inactive assignments; they cannot be re-granted while inactive.</p>@endif
    <input type="hidden" name="permissions" value="">
    @error('permissions')<p class="mt-3 text-sm text-rose-600">{{ $message }}</p>@enderror
    <div class="mt-4 space-y-4">@forelse($permissions as $module => $entries)
        <details class="rounded-xl border border-slate-200" open><summary class="cursor-pointer bg-slate-50 px-4 py-3 text-sm font-semibold">{{ \Illuminate\Support\Str::headline($module) }} <span class="font-normal text-slate-500">({{ $entries->count() }})</span></summary>
            <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">@foreach($entries as $permission)<label class="flex items-start gap-2 text-sm"><input class="mt-1 shrink-0" type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permissions', $selectedPermissions) ?? []))><span><span class="block">{{ $permission->name }}</span><span class="break-all font-mono text-xs text-slate-500">{{ $permission->slug }}</span></span></label>@endforeach</div>
        </details>
    @empty<p class="text-sm text-slate-500">No active permissions are available to grant.</p>@endforelse</div>
</fieldset>
<div class="mt-6 flex gap-3"><button class="button" type="submit">Save role</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.roles.index') }}">Cancel</a></div>
