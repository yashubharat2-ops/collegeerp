@extends('layouts.app')
@section('title', 'Edit Transport Fee Assignment')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit transport fee assignment</h2>
    <p class="panel-subtitle">Bookkeeping fields only — the transport assignment, the fee structure and the snapshotted amount are immutable financial history.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
        <p>
            <span class="font-semibold">Student:</span>
            {{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->first_name }} {{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->last_name }}
            ({{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->student_number ?? '—' }})
            · <span class="font-semibold">Transport:</span> {{ $feeAssignment->studentTransportAssignment?->transportRoute?->name ?? '—' }} / {{ $feeAssignment->studentTransportAssignment?->transportStop?->name ?? '—' }}
            ({{ $feeAssignment->academicYear?->name ?? '—' }})
        </p>
        <p class="mt-1">
            <span class="font-semibold">Structure:</span> {{ $feeAssignment->transportFeeStructure?->name ?? '—' }}
            · <span class="font-semibold">Amount (snapshotted):</span> {{ number_format((float) $feeAssignment->amount, 2) }}
            · <span class="font-semibold">Collected:</span> {{ number_format((float) ($ledger['net_collected'] ?? 0), 2) }}
            · <span class="font-semibold">Outstanding:</span> {{ number_format((float) ($ledger['outstanding'] ?? 0), 2) }}
        </p>
    </div>

    <form method="POST" action="{{ route('transport-fees.update', $feeAssignment->id) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        <label class="block text-sm font-medium text-slate-700">
            Effective from *
            <input class="input mt-1" type="date" name="effective_from" value="{{ old('effective_from', $feeAssignment->effective_from?->format('Y-m-d')) }}" required>
            @error('effective_from')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Effective until
            <input class="input mt-1" type="date" name="effective_until" value="{{ old('effective_until', $feeAssignment->effective_until?->format('Y-m-d')) }}">
            @error('effective_until')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Status *
            <select class="input mt-1" name="status" required>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', $feeAssignment->status) === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            @error('status')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
            Remarks
            <textarea class="input mt-1" name="remarks" maxlength="2000" rows="2">{{ old('remarks', $feeAssignment->remarks) }}</textarea>
            @error('remarks')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <div class="flex gap-2 sm:col-span-2">
            <button class="button" type="submit">Update assignment</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-fees.index') }}">Back to list</a>
        </div>
    </form>

    @can('collect', $feeAssignment)
        @if($feeAssignment->isPayable() && ($ledger['outstanding'] ?? 0) > 0)
            <div class="mt-8">
                <h3 class="font-semibold">Record collection</h3>
                <p class="mt-1 text-xs text-slate-500">Stored as an ordinary Finance payment against this transport fee assignment — the same payment number series and receipts as every collection.</p>
                <div class="mt-3">
                    @include('transport.fees._collect_form', ['feeAssignment' => $feeAssignment, 'paymentModes' => $paymentModes, 'ledger' => $ledger])
                </div>
            </div>
        @endif
    @endcan
</div>
@endsection
