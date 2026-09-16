@extends('layouts.app')
@section('title','Applicants')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Applicants</h2>
            <p class="panel-subtitle">Manage prospective student records within the active college. Person data is single source of truth for enquiries and applications.</p>
        </div>
        @can('create', App\Models\AdmissionApplicant::class)
            <a class="button" href="{{ route('admission-applicants.create') }}">+ New applicant</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-applicants.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search name, email, phone">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-applicants.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($applicants as $applicant)
                    <tr class="border-b">
                        <td class="py-3 font-medium">
                            {{ $applicant->first_name }} {{ $applicant->middle_name }} {{ $applicant->last_name }}
                            @if($applicant->gender)
                                <span class="ml-1 text-xs text-slate-500">({{ $applicant->gender }})</span>
                            @endif
                        </td>
                        <td>{{ $applicant->email ?? '—' }}</td>
                        <td>{{ $applicant->phone ?? '—' }} @if($applicant->alternate_phone) <span class="text-xs text-slate-500">/ {{ $applicant->alternate_phone }}</span> @endif</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $applicant->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($applicant->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $applicant)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-applicants.edit', $applicant) }}">Edit</a>
                                @endcan
                                @can('delete', $applicant)
                                    <form method="POST" action="{{ route('admission-applicants.destroy', $applicant) }}" onsubmit="return confirm(@js('Delete applicant '.$applicant->first_name.' '.$applicant->last_name.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="5">No applicants found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $applicants->firstItem() ?? 0 }}–{{ $applicants->lastItem() ?? 0 }} of {{ $applicants->total() }} applicants.</p>
        {{ $applicants->links() }}
    </div>
</div>
@endsection
