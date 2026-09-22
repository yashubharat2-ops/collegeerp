@extends('layouts.app')

@section('title', 'Designations')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 class="panel-title">Designations</h2><p class="panel-subtitle">Manage college-owned employee designation masters. Codes are unique within this college.</p></div>
        @can('create', App\Models\Designation::class)<a class="button" href="{{ route('designations.create') }}">+ New designation</a>@endcan
    </div>
    <form method="GET" action="{{ route('designations.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name or code">
        <select class="input" name="status"><option value="">All statuses</option><option value="active" @selected($status === 'active')>Active</option><option value="inactive" @selected($status === 'inactive')>Inactive</option></select>
        <div class="flex gap-2"><button class="button" type="submit">Filter</button>@if($search !== '' || $status)<a class="button !bg-slate-200 !text-slate-700" href="{{ route('designations.index') }}">Clear</a>@endif</div>
    </form>
    <div class="mt-8 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3">Name</th><th>Code</th><th>Description</th><th>Employees</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
        @forelse($designations as $designation)
            <tr class="border-b"><td class="py-3 font-medium">{{ $designation->name }}</td><td>{{ $designation->code }}</td><td class="max-w-xs truncate">{{ $designation->description ?: '—' }}</td><td>{{ $designation->employees_count }}</td><td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $designation->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ ucfirst($designation->status) }}</span></td><td class="text-right"><div class="flex justify-end gap-2">@can('view', $designation)<a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('designations.show', $designation) }}">View</a>@endcan @can('update', $designation)<a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('designations.edit', $designation) }}">Edit</a>@endcan @can('delete', $designation)<form method="POST" action="{{ route('designations.destroy', $designation) }}" onsubmit="return confirm(@js('Delete designation '.$designation->name.'?'))">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button></form>@endcan</div></td></tr>
        @empty <tr><td class="py-6 text-slate-500" colspan="6">No designations found.</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2"><p class="text-xs text-slate-500">Showing {{ $designations->firstItem() ?? 0 }}–{{ $designations->lastItem() ?? 0 }} of {{ $designations->total() }} designations.</p>{{ $designations->links() }}</div>
</div>
@endsection
