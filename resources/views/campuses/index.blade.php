@extends('layouts.app')
@section('title','Campuses')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Campuses</h2>
            <p class="panel-subtitle">Manage physical/administrative campuses within the active college.</p>
        </div>
        @can('create', App\Models\Campus::class)
            <a class="button" href="{{ route('campuses.create') }}">+ New campus</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('campuses.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name or code">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('campuses.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Code</th>
                    <th>Short name</th>
                    <th>City</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($campuses as $campus)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $campus->name }}
                            @if($campus->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ $campus->description }}">{{ $campus->description }}</p>
                            @endif
                        </td>
                        <td>{{ $campus->code }}</td>
                        <td>{{ $campus->short_name ?? '—' }}</td>
                        <td>{{ $campus->city ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $campus->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($campus->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $campus)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('campuses.edit', $campus) }}">Edit</a>
                                @endcan
                                @can('delete', $campus)
                                    <form method="POST" action="{{ route('campuses.destroy', $campus) }}" onsubmit="return confirm(@js('Delete campus '.$campus->name.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-slate-500" colspan="6">No campuses found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $campuses->firstItem() ?? 0 }}–{{ $campuses->lastItem() ?? 0 }} of {{ $campuses->total() }} campuses.</p>
        {{ $campuses->links() }}
    </div>
</div>
@endsection
