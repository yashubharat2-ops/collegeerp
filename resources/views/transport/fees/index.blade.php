@extends('layouts.app')
@section('title', 'Transport Fees')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Transport Fees</h2>
            <p class="panel-subtitle">Transport fee assignments against existing student transport assignments. Amounts are snapshotted from the transport fee structure; collections are recorded as ordinary Finance payments and receipts.</p>
        </div>
        <div class="flex gap-2">
            @can('viewAny', App\Models\TransportFeeStructure::class)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-fee-structures.index') }}">Fee structures</a>
            @endcan
            @can('create', App\Models\StudentTransportFeeAssignment::class)
                <a class="button" href="{{ route('transport-fees.create') }}">+ Assign transport fee</a>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('transport-fees.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($years as $year)
                <option value="{{ $year->id }}" @selected((string) $selected['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        <select class="input" name="route_id">
            <option value="">All routes</option>
            @foreach($routes as $route)
                <option value="{{ $route->id }}" @selected((string) $selected['route_id'] === (string) $route->id)>{{ $route->name }}</option>
            @endforeach
        </select>
        <select class="input" name="transport_fee_structure_id">
            <option value="">All structures</option>
            @foreach($structures as $structure)
                <option value="{{ $structure->id }}" @selected((string) $selected['transport_fee_structure_id'] === (string) $structure->id)>{{ $structure->name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if(array_filter($selected))
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-fees.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-slate-500">
                <tr>
                    <th class="whitespace-nowrap px-3 py-3">Student</th>
                    <th class="whitespace-nowrap px-3 py-3">Route / Stop</th>
                    <th class="whitespace-nowrap px-3 py-3">Structure</th>
                    <th class="whitespace-nowrap px-3 py-3">Period</th>
                    <th class="whitespace-nowrap px-3 py-3">Amount</th>
                    <th class="whitespace-nowrap px-3 py-3">Collected</th>
                    <th class="whitespace-nowrap px-3 py-3">Outstanding</th>
                    <th class="whitespace-nowrap px-3 py-3">Status</th>
                    <th class="px-3 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($feeAssignments as $feeAssignment)
                    @php($summary = $feeAssignment->ledger ?? null)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3 font-medium">
                            {{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->first_name }} {{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->last_name }}
                            <span class="block text-xs text-slate-500">{{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->student_number }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs">
                            {{ $feeAssignment->studentTransportAssignment?->transportRoute?->name ?? '—' }}
                            <span class="block text-slate-500">{{ $feeAssignment->studentTransportAssignment?->transportStop?->name ?? '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $feeAssignment->transportFeeStructure?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $feeAssignment->effective_from?->format('d M Y') }} {{ $feeAssignment->effective_until ? '→ '.$feeAssignment->effective_until->format('d M Y') : '' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ number_format((float) $feeAssignment->amount, 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ number_format((float) ($summary['net_collected'] ?? 0), 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3 font-semibold">{{ number_format((float) ($summary['outstanding'] ?? 0), 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($feeAssignment->status === App\Models\StudentTransportFeeAssignment::STATUS_ACTIVE) bg-emerald-100 text-emerald-700
                                @elseif($feeAssignment->status === App\Models\StudentTransportFeeAssignment::STATUS_COMPLETED) bg-slate-100 text-slate-700
                                @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($feeAssignment->status) }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-2">
                                @can('collect', $feeAssignment)
                                    @if($feeAssignment->isPayable() && ($summary['outstanding'] ?? 0) > 0)
                                        <details class="relative">
                                            <summary class="button cursor-pointer list-none">Collect</summary>
                                            <div class="absolute right-0 z-10 mt-2 w-[26rem] max-w-[80vw]">
                                                @include('transport.fees._collect_form', ['feeAssignment' => $feeAssignment, 'paymentModes' => $paymentModes, 'ledger' => $summary])
                                            </div>
                                        </details>
                                    @endif
                                @endcan
                                @can('update', $feeAssignment)<a class="button" href="{{ route('transport-fees.edit', $feeAssignment->id) }}">Edit</a>@endcan
                                @can('delete', $feeAssignment)
                                    <form method="POST" action="{{ route('transport-fees.destroy', $feeAssignment->id) }}" onsubmit="return confirm('Delete this transport fee assignment? History is preserved (soft delete).')">@csrf @method('DELETE')<button class="button !bg-red-600">Delete</button></form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-slate-500">No transport fee assignments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $feeAssignments->links() }}</div>
</div>
@endsection
