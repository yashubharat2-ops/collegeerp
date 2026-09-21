@extends('layouts.app')

@section('title', 'Fee Collection')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Collection</h2>
            <p class="panel-subtitle">Recorded collections. Cancelled payments stay visible for the audit trail but no longer count towards the collected amount.</p>
            <p class="mt-2 text-sm text-slate-600">Filtered total (all shown statuses): <span class="font-semibold">{{ number_format((float) $total, 2) }}</span></p>
        </div>
        @can('create', App\Models\FeePayment::class)
            <a class="button" href="{{ route('fee-collections.create') }}">+ Record collection</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6" method="GET" action="{{ route('fee-collections.index') }}">
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
            <label class="label" for="program_id">Program</label>
            <select class="input" id="program_id" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((int) $selected['program_id'] === $program->id)>{{ $program->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="payment_mode">Payment Mode</label>
            <select class="input" id="payment_mode" name="payment_mode">
                <option value="">All modes</option>
                @foreach($usedPaymentModes as $mode)
                    <option value="{{ $mode }}" @selected($selected['payment_mode'] === $mode)>{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($paymentStatuses as $status)
                    <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="from">Date from / to</label>
            <div class="flex gap-2">
                <input class="input" id="from" name="from" type="date" value="{{ $selected['from'] }}">
                <input class="input" id="to" name="to" type="date" value="{{ $selected['to'] }}">
            </div>
        </div>
        <div class="sm:col-span-3 lg:col-span-6">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Payment No</th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Mode</th>
                    <th>Reference</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $payment->payment_number }}</td>
                        <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                        <td>{{ $payment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $payment->studentEnrollment?->program?->code ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_mode)) }}</td>
                        <td>{{ $payment->reference_number ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) $payment->amount, 2) }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $payment->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ ucfirst($payment->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', App\Models\FeeReceipt::fromPayment($payment))
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.show', $payment) }}">Receipt</a>
                                @endcan
                                @if(! $payment->isCancelled())
                                    @can('update', $payment)
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.edit', $payment) }}">Edit</a>
                                    @endcan
                                    @can('cancel', $payment)
                                        <form method="POST" action="{{ route('fee-collections.cancel', $payment) }}" onsubmit="return confirm(@js('Cancel payment '.$payment->payment_number.'? It will stop counting towards the collected amount.'));">
                                            @csrf
                                            <input type="hidden" name="cancellation_reason" value="Cancelled by {{ auth()->user()->name }}">
                                            <button class="button !bg-amber-100 !text-amber-700" type="submit">Cancel</button>
                                        </form>
                                    @endcan
                                @endif
                                @can('delete', $payment)
                                    <form method="POST" action="{{ route('fee-collections.destroy', $payment) }}" onsubmit="return confirm(@js('Delete payment '.$payment->payment_number.'? Prefer cancelling it so the audit trail keeps the row.'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No collections recorded for this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $payments->links() }}</div>
</div>
@endsection
