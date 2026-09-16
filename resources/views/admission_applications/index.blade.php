@extends('layouts.app')
@section('title','Applications')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Admission Applications</h2>
            <p class="panel-subtitle">Manage formal applications within the active college. Application numbers are generated server-side per college.</p>
        </div>
        @can('create', App\Models\AdmissionApplication::class)
            <a class="button" href="{{ route('admission-applications.create') }}">+ New application</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-applications.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search number, applicant">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="draft" @selected($status === 'draft')>Draft</option>
            <option value="submitted" @selected($status === 'submitted')>Submitted</option>
            <option value="under_review" @selected($status === 'under_review')>Under Review</option>
            <option value="approved" @selected($status === 'approved')>Approved</option>
            <option value="rejected" @selected($status === 'rejected')>Rejected</option>
            <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
            <option value="admitted" @selected($status === 'admitted')>Admitted</option>
        </select>
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
        <div class="flex gap-2 col-span-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status || $academic_year_id || $program_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-applications.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Application No</th>
                    <th>Applicant</th>
                    <th>Academic Year</th>
                    <th>Program</th>
                    <th>Enquiry</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $application->application_number }}</td>
                        <td>
                            <span class="font-medium">{{ $application->applicant->first_name }} {{ $application->applicant->last_name }}</span>
                            <p class="text-xs text-slate-500">{{ $application->applicant->email ?? '' }} {{ $application->applicant->phone ?? '' }}</p>
                        </td>
                        <td>{{ $application->academicYear?->name ?? '—' }}</td>
                        <td>{{ $application->program?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $application->enquiry?->enquiry_number ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($application->status === 'draft') bg-slate-200 text-slate-700
                                @elseif($application->status === 'submitted') bg-blue-100 text-blue-700
                                @elseif($application->status === 'under_review') bg-amber-100 text-amber-700
                                @elseif($application->status === 'approved') bg-emerald-100 text-emerald-700
                                @elseif($application->status === 'rejected') bg-rose-100 text-rose-700
                                @else bg-slate-800 text-white @endif
                            ">{{ ucfirst(str_replace('_',' ',$application->status)) }}</span>
                        </td>
                        <td class="text-xs">{{ $application->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $application)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-applications.edit', $application) }}">Edit</a>
                                @endcan
                                @can('delete', $application)
                                    <form method="POST" action="{{ route('admission-applications.destroy', $application) }}" onsubmit="return confirm(@js('Delete application '.$application->application_number.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="8">No applications found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $applications->firstItem() ?? 0 }}–{{ $applications->lastItem() ?? 0 }} of {{ $applications->total() }} applications.</p>
        {{ $applications->links() }}
    </div>
</div>
@endsection
