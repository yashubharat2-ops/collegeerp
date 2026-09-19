@extends('layouts.app')
@section('title','Transfer / TC')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Transfer / TC</h2>
            <p class="panel-subtitle">
                Request → approve → issue TC. A transfer never deletes a student: statuses change and the student's
                enrollments, academic records and documents are preserved.
            </p>
        </div>
        @can('create', App\Models\StudentTransfer::class)
            <a class="button" href="{{ route('student-transfers.create') }}">+ New transfer request</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('student-transfers.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search TC number, student, institution">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($students as $s)
                <option value="{{ $s->id }}" @selected((string) $student_id === (string) $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All request statuses</option>
            @foreach(App\Models\StudentTransfer::STATUSES as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
            @endforeach
        </select>
        <select class="input" name="tc_status">
            <option value="">All TC statuses</option>
            @foreach(App\Models\StudentTransfer::TC_STATUSES as $option)
                <option value="{{ $option }}" @selected($tc_status === $option)>{{ ucfirst($option) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $student_id || $status || $tc_status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-transfers.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Student</th>
                    <th>Transfer date</th>
                    <th>Destination</th>
                    <th>Request</th>
                    <th>TC</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transfers as $transfer)
                <tr class="border-b">
                    <td class="py-3">
                        <a class="font-medium text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $transfer->student_id, 'tab' => 'transfer']) }}">{{ $transfer->student?->student_number }}</a>
                        <span class="block text-xs text-slate-500">{{ $transfer->student?->fullName() }}</span>
                    </td>
                    <td class="text-xs">{{ $transfer->transfer_date?->format('d M Y') ?? '—' }}</td>
                    <td class="text-xs">{{ $transfer->destination_institution ?? '—' }}</td>
                    <td>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($transfer->isPending()) bg-amber-100 text-amber-700
                            @elseif($transfer->isApproved()) bg-indigo-100 text-indigo-700
                            @else bg-slate-200 text-slate-600 @endif">{{ ucfirst($transfer->status) }}</span>
                        <span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($transfer->reason, 60) }}</span>
                    </td>
                    <td class="text-xs">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($transfer->isTcIssued()) bg-emerald-100 text-emerald-700
                            @else bg-slate-200 text-slate-600 @endif">{{ ucfirst(str_replace('_', ' ', $transfer->tc_status)) }}</span>
                        @if($transfer->tc_number)
                            <span class="block font-medium text-slate-700">{{ $transfer->tc_number }}</span>
                            <span class="block text-slate-500">Issued {{ $transfer->tc_issue_date?->format('d M Y') ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if($transfer->isPending())
                                @can('update', $transfer)
                                    <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('student-transfers.edit', $transfer) }}">Edit</a>
                                @endcan
                                @can('approve', $transfer)
                                    <form class="inline" method="POST" action="{{ route('student-transfers.approve', $transfer) }}"
                                          onsubmit="return confirm(@js('Approve the transfer request for '.$transfer->student?->fullName().'?'))">
                                        @csrf
                                        <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Approve</button>
                                    </form>
                                    <form class="inline" method="POST" action="{{ route('student-transfers.reject', $transfer) }}"
                                          onsubmit="const reason = prompt('Rejection remarks (optional):'); if (reason === null) return false; this.querySelector('[name=remarks]').value = reason; return true;">
                                        @csrf
                                        <input type="hidden" name="remarks" value="">
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Reject</button>
                                    </form>
                                    <form class="inline" method="POST" action="{{ route('student-transfers.cancel', $transfer) }}"
                                          onsubmit="return confirm(@js('Cancel the transfer request for '.$transfer->student?->fullName().'?'))">
                                        @csrf
                                        <button class="text-xs font-semibold text-slate-500 hover:underline" type="submit">Cancel</button>
                                    </form>
                                @endcan
                            @endif

                            @if($transfer->isApproved() && ! $transfer->isTcIssued())
                                @can('issue', $transfer)
                                    <form class="flex w-full flex-wrap items-center justify-end gap-2" method="POST"
                                          action="{{ route('student-transfers.issue', $transfer) }}" enctype="multipart/form-data"
                                          onsubmit="return confirm(@js('Issue the TC for '.$transfer->student?->fullName().'? The student and enrollment become withdrawn; history is preserved.'))">
                                        @csrf
                                        <input class="input !w-36 !py-1 text-xs" type="date" name="tc_issue_date"
                                               value="{{ old('tc_issue_date', now()->format('Y-m-d')) }}" aria-label="TC issue date">
                                        <input class="text-xs" type="file" name="tc_file" accept=".pdf,.jpg,.jpeg,.png" aria-label="TC file (optional)">
                                        <button class="text-xs font-semibold text-indigo-600 hover:underline" type="submit">Issue TC</button>
                                    </form>
                                @endcan
                            @endif

                            @if($transfer->hasTcFile())
                                @can('download', $transfer)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-transfers.download', $transfer) }}">TC file</a>
                                @endcan
                            @endif

                            @if(! $transfer->isTcIssued())
                                @can('delete', $transfer)
                                    <form method="POST" action="{{ route('student-transfers.destroy', $transfer) }}"
                                          onsubmit="return confirm(@js('Delete this transfer request? Issued TCs cannot be deleted.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="6">No transfer requests.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $transfers->firstItem() ?? 0 }}–{{ $transfers->lastItem() ?? 0 }} of {{ $transfers->total() }} requests.</p>
        {{ $transfers->links() }}
    </div>
</div>
@endsection
