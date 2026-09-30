@extends('layouts.app')
@section('title', 'Audit Logs')
@section('content')
@include('administration.partials.context')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="panel-title">Audit Logs</h2><p class="panel-subtitle">Read-only event metadata from the existing immutable audit log. Historical payloads, credentials and request headers are deliberately not exposed.</p></div>
        @can('viewPlatform', App\Models\AuditLog::class)<div class="flex flex-wrap gap-2">@if($platform && app(\App\Support\Tenancy\TenantContext::class)->has())<a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.audit-logs.index') }}">Active college only</a>@elseif(! $platform)<a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.audit-logs.index', ['scope' => 'platform']) }}">Platform / cross-college view</a>@endif</div>@endcan
    </div>
    @if($platform)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Explicit Super Admin platform view. With no college filter, events from all colleges and platform-only events are included.</p>@endif
    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @if($platform)<input type="hidden" name="scope" value="platform"><div><label class="label" for="college_id">College</label><select class="input" name="college_id" id="college_id"><option value="">All colleges + platform</option>@foreach($auditColleges as $college)<option value="{{ $college->id }}" @selected((string) ($filters['college_id'] ?? '') === (string) $college->id)>{{ $college->name }}</option>@endforeach</select></div>@endif
        <div><label class="label" for="user_id">Acting user</label><select class="input" name="user_id" id="user_id"><option value="">All actors</option>@foreach($actors as $actor)<option value="{{ $actor->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $actor->id)>{{ $actor->name }}</option>@endforeach</select></div>
        <div><label class="label" for="module">Action module</label><select class="input" name="module" id="module"><option value="">All modules</option>@foreach($modules as $module)<option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \Illuminate\Support\Str::headline($module) }}</option>@endforeach</select></div>
        <div><label class="label" for="action">Action</label><select class="input" name="action" id="action"><option value="">All actions</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>@endforeach</select></div>
        <div><label class="label" for="date_from">From date (UTC)</label><input class="input" type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div><label class="label" for="date_to">Through date (UTC)</label><input class="input" type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div class="flex items-end gap-2"><button class="button" type="submit">Filter</button><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.audit-logs.index', $platform ? ['scope' => 'platform'] : []) }}">Clear</a></div>
    </form>
    <div class="mt-6 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Event / time (UTC)</th><th class="pr-4">Actor</th><th class="pr-4">Action</th><th class="pr-4">Subject reference</th><th class="pr-4">Route / method</th>@if($platform)<th>College</th>@endif</tr></thead><tbody>
        @forelse($logs as $log)<tr class="border-b align-top"><td class="whitespace-nowrap py-3 pr-4"><p class="font-mono text-xs">#{{ $log->id }}</p><p>{{ $log->created_at?->utc()->format('d M Y, H:i:s') }}</p></td><td class="py-3 pr-4">{{ $log->user?->name ?? 'System / removed user' }}@if($log->user_id)<p class="font-mono text-xs text-slate-500">User #{{ $log->user_id }}</p>@endif</td><td class="py-3 pr-4 font-mono text-xs">{{ $log->action }}</td><td class="py-3 pr-4 text-xs">{{ $log->subject_type ? class_basename($log->subject_type) : '—' }}@if($log->subject_id) #{{ $log->subject_id }} @endif</td><td class="py-3 pr-4 text-xs">{{ $log->route_name ?? '—' }}<p class="mt-1 text-slate-500">{{ $log->method }}</p></td>@if($platform)<td class="py-3">{{ $log->college?->name ?? ($log->college_id ? 'Removed college' : 'Platform') }}</td>@endif</tr>
        @empty<tr><td class="py-6 text-slate-500" colspan="{{ $platform ? 6 : 5 }}">No audit events match the filters in this scope.</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500">{{ $logs->total() }} matching events. No edit, delete or export actions are available.</p>{{ $logs->links() }}</div>
</div>
@endsection
