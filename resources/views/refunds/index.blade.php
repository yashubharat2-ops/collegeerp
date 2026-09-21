@extends('layouts.app')

@section('title', 'Refunds')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Refunds</h2>
            <p class="panel-subtitle">
                Refunds always reference an actual collection. A refund reduces the net collected amount from the moment it is
                recorded; rejected and cancelled refunds stop reducing it. Refund records are never deleted.
            </p>
        </div>
        @can('create', App\Models\FeeRefund::class)
            <a class="button" href="{{ route('refunds.create') }}">+ Add refund</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 xl:grid-cols-7" method="GET" action="{{ route('refunds.index') }}">
        <div>
            <label class="label" for="student_id">Student</label>
            <select class="input" id="student_id" name="student_id">
                <option value="">All students</option>
                @foreach($students as $student)
                    <option value="{{ $student->id }}" @selected((int) $selected['student_id'] === $student->id)>{{ $student->student_number }} · {{ $student->fullName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="academic_year_id">Academic Year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((int) $selected['academic_year_id'] === $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($refundStatuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($selected['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        {{--
            Date range — two native date controls are the widest pair in the
            filter bar. They live in a two-column grid of `minmax(0, 1fr)` tracks
            (Tailwind's `grid-cols-2`) and each control carries `min-w-0`, so a
            control shrinks with its track instead of pushing past the card; the
            cell spans two tracks of the filter grid so both dates stay readable
            and the row wraps at every breakpoint.
        --}}
        <div class="sm:col-span-2">
            <label class="label" for="from">Date from / to</label>
            <div class="grid grid-cols-2 gap-2">
                <input class="input min-w-0" id="from" name="from" type="date" aria-label="Date from" value="{{ $selected['from'] }}">
                <input class="input min-w-0" id="to" name="to" type="date" aria-label="Date to" value="{{ $selected['to'] }}">
            </div>
        </div>
        <div class="sm:col-span-2 lg:col-span-5 xl:col-span-7 flex flex-wrap items-end gap-2">
            <button class="button" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Refund No</th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Payment No</th>
                    <th class="text-right">Payment Amount</th>
                    <th class="text-right">Refund Amount</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Approved / Processed</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($refunds as $refund)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $refund->refund_number }}</td>
                        <td>{{ $refund->refund_date?->format('Y-m-d') }}</td>
                        <td>{{ $refund->payment?->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $refund->payment?->payment_number ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) $refund->payment?->amount, 2) }}</td>
                        <td class="text-right font-semibold">{{ number_format((float) $refund->amount, 2) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($refund->reason ?? '—', 30) }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $refund->status === 'processed' ? 'bg-emerald-100 text-emerald-700' : ($refund->status === 'approved' ? 'bg-sky-100 text-sky-700' : ($refund->status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600')) }}">{{ ucfirst($refund->status) }}</span>
                        </td>
                        <td class="text-xs">
                            @if($refund->approved_at)
                                {{ $refund->approved_at->format('Y-m-d') }} · {{ $refund->approver?->name ?? '—' }}<br>
                            @endif
                            @if($refund->processed_at)
                                {{ $refund->processed_at->format('Y-m-d') }} · {{ $refund->processor?->name ?? '—' }}
                            @endif
                            @if(! $refund->approved_at && ! $refund->processed_at)
                                —
                            @endif
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @if($refund->status === 'pending')
                                    @can('approve', $refund)
                                        <form method="POST" action="{{ route('refunds.approve', $refund) }}" onsubmit="return confirm(@js('Approve refund '.$refund->refund_number.'?'));">
                                            @csrf
                                            <button class="button !bg-emerald-100 !text-emerald-700" type="submit">Approve</button>
                                        </form>
                                    @endcan
                                @endif
                                @if($refund->status === 'approved')
                                    @can('process', $refund)
                                        <form method="POST" action="{{ route('refunds.process', $refund) }}" onsubmit="return confirm(@js('Mark refund '.$refund->refund_number.' as processed?'));">
                                            @csrf
                                            <button class="button !bg-sky-100 !text-sky-700" type="submit">Process</button>
                                        </form>
                                    @endcan
                                @endif
                                @if(! $refund->isProcessed() && ! in_array($refund->status, ['rejected', 'cancelled'], true))
                                    @can('update', $refund)
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('refunds.edit', $refund) }}">Edit</a>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="10">No refunds recorded for this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $refunds->links() }}</div>
</div>
@endsection
