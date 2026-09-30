@extends('layouts.app')
@section('title', 'Permissions')
@section('content')
@include('administration.partials.context')
<div class="space-y-6">
    <div class="panel"><h2 class="panel-title">Central permission registry</h2><p class="panel-subtitle">Live entries from the existing permissions table. Definitions and activation are deployment-managed; role assignments use the same RBAC editor as Roles.</p>
        <form method="GET" action="{{ route('admin.permissions.index') }}" class="mt-6 grid gap-3 md:grid-cols-3">
            <div><label class="label" for="search">Permission name or identifier</label><input class="input" id="search" type="search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></div>
            <div><label class="label" for="module">Module</label><select class="input" id="module" name="module"><option value="">All modules</option>@foreach($modules as $module)<option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \Illuminate\Support\Str::headline($module) }}</option>@endforeach</select></div>
            <div class="flex items-end gap-2"><button class="button" type="submit">Filter</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.permissions.index') }}">Clear</a></div>
        </form>
    </div>
    @if($editableRoles->isNotEmpty())
        <div class="panel"><h3 class="panel-title">Manage role assignments</h3><p class="panel-subtitle">Choose a role you are authorized to maintain. System/platform restrictions and your permission grant ceiling also apply in its editor.</p><div class="mt-4 flex flex-wrap gap-2">@foreach($editableRoles as $role)<a class="button !bg-indigo-50 !text-indigo-700" href="{{ route('admin.roles.edit', $role) }}#permissions">{{ $role->name }} · Assign permissions</a>@endforeach</div></div>
    @endif
    @forelse($permissions as $module => $entries)
        <div class="panel"><h3 class="panel-title">{{ \Illuminate\Support\Str::headline($module) }} <span class="text-sm font-normal text-slate-500">{{ $entries->count() }} permissions</span></h3>
            <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Permission</th><th class="pr-4">Identifier</th><th class="pr-4">Action</th><th>Status</th></tr></thead><tbody>@foreach($entries as $permission)<tr class="border-b"><td class="py-3 pr-4"><p class="font-medium">{{ $permission->name }}</p>@if($permission->description)<p class="mt-1 max-w-lg text-xs text-slate-500">{{ $permission->description }}</p>@endif</td><td class="pr-4 font-mono text-xs">{{ $permission->slug }}</td><td class="pr-4">{{ $permission->action }}</td><td>@include('administration.partials.status', ['active' => $permission->is_active])</td></tr>@endforeach</tbody></table></div>
        </div>
    @empty<div class="panel"><p class="text-sm text-slate-500">No registry entries match the filters.</p></div>@endforelse
</div>
@endsection
