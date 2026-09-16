@extends('layouts.app')
@section('title','Merit Lists')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Merit / Selection Lists</h2>
            <p class="panel-subtitle">Configurable foundation for admission selection. Supports published/unpublished, selection status, rank and score.</p>
        </div>
        @can('create', App\Models\AdmissionMeritList::class)
            <a class="button" href="{{ route('admission-merit-lists.create') }}">+ New merit list</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-merit-lists.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search code, name">
        <select class="input" name="academic_year_id">
            <option value="">All years</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((string)$academic_year_id === (string)$ay->id)>{{ $ay->name }}</option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((string)$program_id === (string)$prog->id)>{{ $prog->name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="draft" @selected($status === 'draft')>Draft</option>
            <option value="published" @selected($status === 'published')>Published</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $academic_year_id || $program_id || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-merit-lists.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3">Code</th><th>Name</th><th>Year</th><th>Program</th><th>Status</th><th>Published</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($meritLists as $list)
                <tr class="border-b">
                    <td class="py-3 font-medium">{{ $list->code }}</td>
                    <td>{{ $list->name }}</td>
                    <td>{{ $list->academicYear?->name ?? '—' }}</td>
                    <td>{{ $list->program?->name ?? '—' }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $list->status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">{{ ucfirst($list->status) }}</span></td>
                    <td class="text-xs">{{ $list->is_published ? ($list->published_at?->format('Y-m-d H:i') ?? 'Yes') : 'No' }}</td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('view', $list)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-merit-lists.show', $list) }}">View</a>
                            @endcan
                            @can('update', $list)
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('admission-merit-lists.edit', $list) }}">Edit</a>
                            @endcan
                            @can('publish', $list)
                                @if(!$list->is_published)
                                <form method="POST" action="{{ route('admission-merit-lists.publish', $list) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Publish</button>
                                </form>
                                @else
                                <form method="POST" action="{{ route('admission-merit-lists.unpublish', $list) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-amber-600 hover:underline" type="submit">Unpublish</button>
                                </form>
                                @endif
                            @endcan
                            @can('delete', $list)
                                <form method="POST" action="{{ route('admission-merit-lists.destroy', $list) }}" onsubmit="return confirm(@js('Delete merit list '.$list->code.'?'))">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="7">No merit lists found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">Showing {{ $meritLists->firstItem() ?? 0 }}–{{ $meritLists->lastItem() ?? 0 }} of {{ $meritLists->total() }} lists.</p>
        {{ $meritLists->links() }}
    </div>
</div>
@endsection
