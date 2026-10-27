@extends('layouts.app')

@section('title', 'Fee Collection')

@section('content')
<div class="panel" data-bulk-scope>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Collection</h2>
            <p class="panel-subtitle">Recorded collections. Cancelled payments stay visible for the audit trail but no longer count towards the collected amount. When Approval Management requires approval for Fee Collection, a new collection stays <strong>Pending Approval</strong> — it is not money, and it appears in no balance, receipt or report, until an authorized approver finalizes it.</p>
            <p class="mt-2 text-sm text-slate-600">Filtered total (every shown status, so pending and reversed rows are included — this is not the collected amount): <span class="font-semibold">{{ number_format((float) $total, 2) }}</span></p>
        </div>
        @can('create', App\Models\FeePayment::class)
            <a class="button" href="{{ route('fee-collections.create') }}">+ Record collection</a>
        @endcan
    </div>

    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :identity-fields="$studentFilterIdentityFields"
        :identity-action="$studentFilterIdentityAction"
        :identity-return-to="$studentFilterIdentityReturnTo"
        :identity-active="$studentFilterIdentityActive"
        :identity-token="$studentFilterIdentityToken"
        :action="route('fee-collections.index')"
        :preserve="[
            'student_id' => request('student_id'),
            'student_enrollment_id' => request('student_enrollment_id'),
            'fee_structure_id' => request('fee_structure_id'),
            'payment_mode' => request('payment_mode'),
            'status' => request('status'),
            'from' => request('from'),
            'to' => request('to'),
        ]"
        title="Filter Students for Fee Collection"
    />

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7" method="GET" action="{{ route('fee-collections.index') }}">
        @foreach($studentFilterInput as $filterName => $filterValue)
            <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
        @endforeach

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
                    <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
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
        <div class="sm:col-span-2 lg:col-span-4 xl:col-span-7 flex flex-wrap items-end gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Reset</a>
        </div>
    </form>
    {{-- Bulk selection over the filtered fee collections list. Export only — the shared
         handler re-queries every ticked id inside the active college and re-authorizes
         each record before the CSV endpoint streams. --}}
    <x-list.bulk-selection-bar module="fee_collections">
        @if(auth()->user()?->hasPermission('fee_collections.view'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export selected
            </button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Payment No</th>
                    <th class="w-10 py-2"><x-list.select-all /></th>
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
                        <td class="py-2"><x-list.row-checkbox :id="$payment->id" /></td>
                        <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                        <td>{{ $payment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $payment->studentEnrollment?->program?->code ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_mode)) }}</td>
                        <td>{{ $payment->reference_number ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) $payment->amount, 2) }}</td>
                        <td>
                            @php
                                // Semantic status colours: green = money, amber = undecided,
                                // rose = never money (rejected / reversed).
                                $statusClass = match ($payment->status) {
                                    App\Models\FeePayment::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-700',
                                    App\Models\FeePayment::STATUS_PENDING_APPROVAL => 'bg-amber-100 text-amber-800',
                                    default => 'bg-rose-100 text-rose-700',
                                };
                            @endphp
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">{{ ucfirst(str_replace('_', ' ', $payment->status)) }}</span>
                            @if($payment->isPendingApproval())
                                <p class="mt-1 text-xs text-amber-700">Awaiting approval — not counted as collected.</p>
                            @endif
                            @if($payment->isRejected())
                                <p class="mt-1 text-xs text-rose-700">Rejected — not counted as collected.</p>
                            @endif
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', App\Models\FeeReceipt::fromPayment($payment))
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('receipts.show', $payment) }}">Receipt</a>
                                @endcan
                                {{-- A collection awaiting approval is not editable or reversible
                                     from here: an approver decides it, or the submitter withdraws
                                     the request under Approval Management → My Requests. --}}
                                @if(! $payment->isCancelled() && ! $payment->isPendingApproval())
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
                                @if(! $payment->isPendingApproval())
                                @can('delete', $payment)
                                    <form method="POST" action="{{ route('fee-collections.destroy', $payment) }}" onsubmit="return confirm(@js('Delete payment '.$payment->payment_number.'? Prefer cancelling it so the audit trail keeps the row.'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="10">No collections recorded for this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $payments->links() }}</div>
</div>
@endsection
