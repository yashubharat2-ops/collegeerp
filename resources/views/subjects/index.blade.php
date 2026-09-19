@extends('layouts.app')

@section('title', 'Subjects')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Subjects</h2>
            <p class="panel-subtitle">Manage courses and subject offerings within the active college.</p>
        </div>
        @can('create', App\Models\Subject::class)
            <a class="button" href="{{ route('subjects.create') }}">+ New subject</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('subjects.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name or code">
        <select class="input" name="department_id">
            <option value="">All departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" @selected((int) $department_id === $dept->id)>
                    {{ $dept->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="subject_type">
            <option value="">All types</option>
            @foreach($types as $t)
                <option value="{{ $t }}" @selected($subject_type === $t)>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $department_id || $subject_type || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('subjects.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th>Type</th>
                    <th>Credits</th>
                    <th>Marks (Pass / Max)</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($subjects as $sub)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $sub->name }}
                            @if($sub->short_name)
                                <span class="text-xs text-slate-500">({{ $sub->short_name }})</span>
                            @endif
                            @if($sub->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ $sub->description }}">{{ $sub->description }}</p>
                            @endif
                        </td>
                        <td>{{ $sub->code }}</td>
                        <td>{{ $sub->department?->name ?? '— (college level)' }}</td>
                        <td>{{ $sub->subject_type ? ucfirst($sub->subject_type) : '—' }}</td>
                        <td>{{ $sub->credits ?? '—' }}</td>
                        <td>
                            @if($sub->max_marks !== null || $sub->passing_marks !== null)
                                {{ $sub->passing_marks ?? '—' }} / {{ $sub->max_marks ?? '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $sub->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($sub->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $sub)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('subjects.edit', $sub) }}">Edit</a>
                                @endcan
                                @can('delete', $sub)
                                    <form method="POST" action="{{ route('subjects.destroy', $sub) }}" onsubmit="return confirm(@js('Delete subject '.$sub->name.'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="8">No subjects found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $subjects->firstItem() ?? 0 }}–{{ $subjects->lastItem() ?? 0 }} of {{ $subjects->total() }} subjects.</p>
        {{ $subjects->links() }}
    </div>
</div>
@endsection
