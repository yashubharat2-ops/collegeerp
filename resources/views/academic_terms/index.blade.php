@extends('layouts.app')

@section('title', 'Academic Terms')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Academic Terms / Semesters</h2>
            <p class="panel-subtitle">Manage semesters and terms across academic years within the active college.</p>
        </div>
        @can('create', App\Models\AcademicTerm::class)
            <a class="button" href="{{ route('academic-terms.create') }}">+ New academic term</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('academic-terms.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name or code">
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $academic_year_id === $year->id)>
                    {{ $year->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="type">
            <option value="">All types</option>
            @foreach($types as $t)
                <option value="{{ $t }}" @selected($type === $t)>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $academic_year_id || $type || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('academic-terms.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Code</th>
                    <th>Academic Year</th>
                    <th>Type</th>
                    <th>Sequence</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($academicTerms as $term)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $term->name }}
                            @if($term->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500" title="{{ $term->description }}">{{ $term->description }}</p>
                            @endif
                        </td>
                        <td>{{ $term->code }}</td>
                        <td>{{ $term->academicYear?->name ?? '—' }}</td>
                        <td>{{ ucfirst($term->type) }}</td>
                        <td>{{ $term->sequence }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $term->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($term->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $term)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('academic-terms.edit', $term) }}">Edit</a>
                                @endcan
                                @can('delete', $term)
                                    <form method="POST" action="{{ route('academic-terms.destroy', $term) }}" onsubmit="return confirm(@js('Delete academic term '.$term->name.'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="7">No academic terms found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $academicTerms->firstItem() ?? 0 }}–{{ $academicTerms->lastItem() ?? 0 }} of {{ $academicTerms->total() }} terms.</p>
        {{ $academicTerms->links() }}
    </div>
</div>
@endsection
