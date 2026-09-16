@extends('layouts.app')
@section('title','Enquiries')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Admission Enquiries</h2>
            <p class="panel-subtitle">Track prospective students before formal application. Enquiry number is generated server-side per college.</p>
        </div>
        @can('create', App\Models\AdmissionEnquiry::class)
            <a class="button" href="{{ route('admission-enquiries.create') }}">+ New enquiry</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-enquiries.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search number, applicant">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="new" @selected($status === 'new')>New</option>
            <option value="contacted" @selected($status === 'contacted')>Contacted</option>
            <option value="followed_up" @selected($status === 'followed_up')>Followed Up</option>
            <option value="converted" @selected($status === 'converted')>Converted</option>
            <option value="closed" @selected($status === 'closed')>Closed</option>
            <option value="dropped" @selected($status === 'dropped')>Dropped</option>
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
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-enquiries.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Enquiry No</th>
                    <th>Applicant</th>
                    <th>Academic Year</th>
                    <th>Program</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Follow Up</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($enquiries as $enquiry)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $enquiry->enquiry_number }}</td>
                        <td>
                            <span class="font-medium">{{ $enquiry->applicant->first_name }} {{ $enquiry->applicant->last_name }}</span>
                            <p class="text-xs text-slate-500">{{ $enquiry->applicant->email ?? '' }} {{ $enquiry->applicant->phone ?? '' }}</p>
                        </td>
                        <td>{{ $enquiry->academicYear?->name ?? '—' }}</td>
                        <td>{{ $enquiry->program?->name ?? '—' }}</td>
                        <td>{{ $enquiry->source ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($enquiry->status === 'new') bg-slate-200 text-slate-700
                                @elseif($enquiry->status === 'contacted') bg-blue-100 text-blue-700
                                @elseif($enquiry->status === 'followed_up') bg-amber-100 text-amber-700
                                @elseif($enquiry->status === 'converted') bg-emerald-100 text-emerald-700
                                @elseif($enquiry->status === 'closed') bg-slate-800 text-white
                                @else bg-rose-100 text-rose-700 @endif
                            ">{{ ucfirst(str_replace('_',' ',$enquiry->status)) }}</span>
                        </td>
                        <td class="text-xs">{{ $enquiry->next_follow_up_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $enquiry)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-enquiries.edit', $enquiry) }}">Edit</a>
                                @endcan
                                @can('delete', $enquiry)
                                    <form method="POST" action="{{ route('admission-enquiries.destroy', $enquiry) }}" onsubmit="return confirm(@js('Delete enquiry '.$enquiry->enquiry_number.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="8">No enquiries found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $enquiries->firstItem() ?? 0 }}–{{ $enquiries->lastItem() ?? 0 }} of {{ $enquiries->total() }} enquiries.</p>
        {{ $enquiries->links() }}
    </div>
</div>
@endsection
