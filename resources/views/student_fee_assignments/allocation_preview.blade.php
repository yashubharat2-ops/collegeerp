@extends('layouts.app')
@section('title', 'Preview Fee Head Allocation')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Preview Fee Head Allocation</h2>
            <p class="panel-subtitle">{{ $result['fee_structure_name'] }} · {{ $result['selection_mode'] === 'all_filtered' ? 'All filtered enrollments' : 'Explicit page/row selection' }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-fee-assignments.allocate') }}">Change selection</a>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-4" aria-label="Allocation preview totals">
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Students</div><div class="text-xl font-semibold">{{ $result['student_count'] }}</div></div>
        <div class="rounded-lg bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Eligible assignments</div><div class="text-xl font-semibold">{{ $result['eligible_count'] }}</div></div>
        <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Skipped assignments</div><div class="text-xl font-semibold">{{ $result['skipped_count'] }}</div></div>
        <div class="rounded-lg bg-indigo-50 p-3"><div class="text-xs text-indigo-700">Total snapshot amount</div><div class="text-xl font-semibold">{{ number_format((float) $result['total_amount'], 2) }}</div></div>
    </div>

    @if($result['truncated'])
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">The tables below are capped at {{ App\Domain\Finance\Services\FeeHeadAllocationService::SAMPLE_LIMIT }} rows each for display. Counts and totals include the complete filtered result.</p>
    @endif

    <h3 class="mt-6 text-sm font-semibold">Eligible Fee Head assignments</h3>
    <div class="erp-list-scroll mt-2"><table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th>REG.NO</th><th>NAME OF STUDENT</th><th>ENROLLMENT NO.</th><th>Fee Head</th><th class="text-right">Amount</th></tr></thead>
        <tbody>@forelse($result['rows'] as $row)<tr class="border-b"><td class="py-2">{{ $row['student_number'] }}</td><td>{{ $row['student_name'] }}</td><td>{{ $row['enrollment_number'] }}</td><td>{{ $row['fee_head_name'] }}</td><td class="text-right">{{ number_format((float) $row['amount'], 2) }}</td></tr>@empty<tr><td colspan="5" class="py-5 text-center text-slate-500">No eligible assignments.</td></tr>@endforelse</tbody>
    </table></div>

    <h3 class="mt-6 text-sm font-semibold">Skipped / duplicate outcomes</h3>
    <div class="erp-list-scroll mt-2"><table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th>REG.NO / Enrollment ID</th><th>ENROLLMENT NO.</th><th>Fee Head</th><th>Reason</th></tr></thead>
        <tbody>@forelse($result['skips'] as $skip)<tr class="border-b"><td class="py-2">{{ $skip['student_number'] ?? $skip['student_enrollment_id'] }}</td><td>{{ $skip['enrollment_number'] ?? '—' }}</td><td>{{ $skip['fee_head_name'] }}</td><td><span class="font-medium">{{ str_replace('_', ' ', $skip['reason']) }}</span><span class="block text-xs text-slate-500">{{ $skip['message'] }}</span></td></tr>@empty<tr><td colspan="4" class="py-5 text-center text-slate-500">No skips.</td></tr>@endforelse</tbody>
    </table></div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
        <p class="max-w-2xl text-xs text-slate-500">Confirmation re-runs authorization, tenant scope, filters, eligibility and duplicate checks under locks. The preview expires in 30 minutes. Only assignments are created—never payments or receipts.</p>
        @if($result['eligible_count'] > 0)
            <form method="POST" action="{{ route('student-fee-assignments.allocation.confirm') }}">
                @csrf
                <input type="hidden" name="allocation_token" value="{{ $allocationToken }}">
                <button class="button" type="submit">Confirm {{ $result['eligible_count'] }} assignments</button>
            </form>
        @endif
    </div>
</div>
@endsection
