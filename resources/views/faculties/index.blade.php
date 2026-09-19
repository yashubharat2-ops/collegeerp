@extends('layouts.app')

@section('title', 'Faculty & Staff')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Faculty / Staff</h2>
            <p class="panel-subtitle">Manage institutional faculty and academic staff members within the active college.</p>
        </div>
        @can('create', App\Models\Faculty::class)
            <a class="button" href="{{ route('faculties.create') }}">+ New faculty member</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('faculties.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search code, name, email, role">
        <select class="input" name="department_id">
            <option value="">All departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" @selected((int) $department_id === $dept->id)>
                    {{ $dept->name }}
                </option>
            @endforeach
        </select>
        <select class="input" name="employment_type">
            <option value="">All employment types</option>
            @foreach($employmentTypes as $type)
                <option value="{{ $type }}" @selected($employment_type === $type)>
                    {{ ucwords(str_replace('_', ' ', $type)) }}
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
            @if($search !== '' || $department_id || $employment_type || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('faculties.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Employee Code</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Contact</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($faculties as $fac)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $fac->full_name }}
                        </td>
                        <td>{{ $fac->employee_code }}</td>
                        <td>{{ $fac->department?->name ?? '— (college level)' }}</td>
                        <td>{{ $fac->designation ?? '—' }}</td>
                        <td>
                            @if($fac->email)
                                <div>{{ $fac->email }}</div>
                            @endif
                            @if($fac->phone)
                                <div class="text-xs text-slate-500">{{ $fac->phone }}</div>
                            @endif
                            @if(! $fac->email && ! $fac->phone)
                                —
                            @endif
                        </td>
                        <td>{{ $fac->employment_type ? ucwords(str_replace('_', ' ', $fac->employment_type)) : '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $fac->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($fac->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $fac)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('faculties.edit', $fac) }}">Edit</a>
                                @endcan
                                @can('delete', $fac)
                                    <form method="POST" action="{{ route('faculties.destroy', $fac) }}" onsubmit="return confirm(@js('Delete faculty member '.$fac->full_name.'? This can be undone by an administrator.'))">
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
                        <td class="py-6 text-slate-500" colspan="8">No faculty members found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $faculties->firstItem() ?? 0 }}–{{ $faculties->lastItem() ?? 0 }} of {{ $faculties->total() }} faculty members.</p>
        {{ $faculties->links() }}
    </div>
</div>
@endsection
