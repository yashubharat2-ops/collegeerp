@extends('layouts.app')

@section('title', 'Sections')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Sections / Batches</h2>
            <p class="panel-subtitle">Manage class sections and cohorts across programs within the active college.</p>
        </div>
        @can('create', App\Models\Section::class)
            <a class="button" href="{{ route('sections.create') }}">+ New section</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('sections.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name or code">
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $academic_year_id === $year->id)>
                    {{ $year->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) $program_id === $prog->id)>
                    {{ $prog->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="campus_id">
            <option value="">All campuses</option>
            @foreach($campuses as $camp)
                <option value="{{ $camp->id }}" @selected((int) $campus_id === $camp->id)>
                    {{ $camp->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $academic_year_id || $program_id || $campus_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('sections.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Code</th>
                    <th>Program</th>
                    <th>Academic Year</th>
                    <th>Campus</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sections as $sec)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $sec->name }}
                            @if($sec->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ $sec->description }}">{{ $sec->description }}</p>
                            @endif
                        </td>
                        <td>{{ $sec->code }}</td>
                        <td>{{ $sec->program?->name ?? '—' }}</td>
                        <td>{{ $sec->academicYear?->name ?? '—' }}</td>
                        <td>{{ $sec->campus?->name ?? '— (college level)' }}</td>
                        <td>{{ $sec->capacity ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $sec->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($sec->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $sec)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('sections.edit', $sec) }}">Edit</a>
                                @endcan
                                @can('delete', $sec)
                                    <form method="POST" action="{{ route('sections.destroy', $sec) }}" onsubmit="return confirm(@js('Delete section '.$sec->name.'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="8">No sections found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $sections->firstItem() ?? 0 }}–{{ $sections->lastItem() ?? 0 }} of {{ $sections->total() }} sections.</p>
        {{ $sections->links() }}
    </div>
</div>
@endsection
