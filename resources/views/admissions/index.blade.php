@extends('layouts.app')
@section('title','Admissions')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Final Admissions / Enrollment</h2>
            <p class="panel-subtitle">Approved/selected applications become admission records. Admission number generated server-side per college. Integration boundary for future Student module via applicant_id.</p>
        </div>
        @can('create', App\Models\Admission::class)
            <a class="button" href="{{ route('admissions.create') }}">+ New admission</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admissions.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search admission, applicant, application">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="completed" @selected($status === 'completed')>Completed</option>
            <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
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
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status || $academic_year_id || $program_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admissions.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3">Admission No</th><th>Applicant</th><th>Application</th><th>Year</th><th>Program</th><th>Date</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($admissions as $adm)
                <tr class="border-b">
                    <td class="py-3 font-medium">{{ $adm->admission_number }}</td>
                    <td>{{ $adm->applicant->first_name }} {{ $adm->applicant->last_name }}<p class="text-xs text-slate-500">{{ $adm->applicant->email ?? '' }}</p></td>
                    <td class="text-xs">{{ $adm->application->application_number ?? '—' }}</td>
                    <td>{{ $adm->academicYear?->name ?? '—' }}</td>
                    <td>{{ $adm->program?->name ?? '—' }}</td>
                    <td class="text-xs">{{ $adm->admission_date?->format('Y-m-d') ?? '—' }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $adm->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($adm->status === 'cancelled' ? 'bg-rose-100 text-rose-700' : 'bg-slate-200 text-slate-700') }}">{{ ucfirst($adm->status) }}</span></td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('update', $adm)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admissions.edit', $adm) }}">Edit</a>
                                @if($adm->status !== 'cancelled')
                                <form method="POST" action="{{ route('admissions.cancel', $adm) }}" onsubmit="return confirm('Cancel admission {{ $adm->admission_number }}?')">
                                    @csrf
                                    <button class="text-xs font-semibold text-amber-600 hover:underline" type="submit">Cancel</button>
                                </form>
                                @endif
                            @endcan
                            @can('delete', $adm)
                                <form method="POST" action="{{ route('admissions.destroy', $adm) }}" onsubmit="return confirm(@js('Delete admission '.$adm->admission_number.'?'))">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No admissions found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">Showing {{ $admissions->firstItem() ?? 0 }}–{{ $admissions->lastItem() ?? 0 }} of {{ $admissions->total() }} admissions.</p>
        {{ $admissions->links() }}
    </div>
</div>
@endsection
