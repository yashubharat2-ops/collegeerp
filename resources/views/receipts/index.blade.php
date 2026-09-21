@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Receipts</h2>
            <p class="panel-subtitle">Receipts are issued for successful collections only. The receipt number is the payment number, and a cancelled payment never appears here.</p>
            <p class="mt-2 text-sm text-slate-600">Issued total: <span class="font-semibold">{{ number_format((float) $total, 2) }}</span></p>
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6" method="GET" action="{{ route('receipts.index') }}">
        <div>
            <label class="label" for="search">Receipt / reference</label>
            <input class="input" id="search" name="search" type="text" value="{{ $selected['search'] }}">
        </div>
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
            <label class="label" for="from">Date from / to</label>
            <div class="flex gap-2">
                <input class="input" id="from" name="from" type="date" value="{{ $selected['from'] }}">
                <input class="input" id="to" name="to" type="date" value="{{ $selected['to'] }}">
            </div>
        </div>
        <div class="sm:col-span-3 lg:col-span-6">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Receipt No</th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Year</th>
                    <th>Mode</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($receipts as $payment)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $payment->payment_number }}</td>
                        <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                        <td>{{ $payment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $payment->studentEnrollment?->academicYear?->name ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_mode)) }}</td>
                        <td class="text-right">{{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', App\Models\FeeReceipt::fromPayment($payment))
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.show', $payment) }}">View</a>
                                @endcan
                                @can('print', App\Models\FeeReceipt::fromPayment($payment))
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.print', $payment) }}">Print</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No receipts issued for this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $receipts->links() }}</div>
</div>
@endsection
