@extends('layouts.app')
@section('title', 'Fee Head Allocation Result')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Head Allocation Result</h2>
            <p class="panel-subtitle">{{ $result['fee_structure_name'] }} · Batch {{ $result['batch_id'] }}</p>
        </div>
        <a class="button" href="{{ route('student-fee-assignments.index') }}">View assignments</a>
    </div>

    @if($result['idempotent'])
        <div class="mt-5 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900" role="status">This batch was already confirmed. {{ $result['existing_count'] }} existing assignments were returned and no new rows were written.</div>
    @else
        <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">Confirmed {{ $result['created_count'] }} Fee Head assignments for {{ $result['student_count'] }} students. {{ $result['skipped_count'] }} potential assignments were skipped. No payment or receipt was created.</div>
    @endif

    <div class="mt-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Created this request</div><div class="text-xl font-semibold">{{ $result['created_count'] }}</div></div>
        <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Skipped</div><div class="text-xl font-semibold">{{ $result['skipped_count'] }}</div></div>
        <div class="rounded-lg bg-indigo-50 p-3"><div class="text-xs text-indigo-700">{{ $result['idempotent'] ? 'Existing batch amount' : 'Created snapshot amount' }}</div><div class="text-xl font-semibold">{{ number_format((float) $result['total_amount'], 2) }}</div></div>
    </div>

    @if($result['truncated'])<p class="mt-4 text-sm text-amber-700">Result tables are display-capped; batch counts cover the complete operation.</p>@endif
    <div class="erp-list-scroll mt-5"><table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th>REG.NO</th><th>NAME OF STUDENT</th><th>ENROLLMENT NO.</th><th>Fee Head</th><th class="text-right">Amount</th></tr></thead>
        <tbody>@forelse($result['rows'] as $row)<tr class="border-b"><td class="py-2">{{ $row['student_number'] }}</td><td>{{ $row['student_name'] }}</td><td>{{ $row['enrollment_number'] }}</td><td>{{ $row['fee_head_name'] }}</td><td class="text-right">{{ number_format((float) $row['amount'], 2) }}</td></tr>@empty<tr><td colspan="5" class="py-5 text-center text-slate-500">No assignments in this result.</td></tr>@endforelse</tbody>
    </table></div>

    @if(!$result['idempotent'] && $result['skips'] !== [])
        <h3 class="mt-6 text-sm font-semibold">Partial outcomes / skips</h3>
        <div class="erp-list-scroll mt-2"><table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th>REG.NO / Enrollment ID</th><th>Fee Head</th><th>Reason</th></tr></thead>
            <tbody>@foreach($result['skips'] as $skip)<tr class="border-b"><td class="py-2">{{ $skip['student_number'] ?? $skip['student_enrollment_id'] }}</td><td>{{ $skip['fee_head_name'] }}</td><td>{{ str_replace('_', ' ', $skip['reason']) }}<span class="block text-xs text-slate-500">{{ $skip['message'] }}</span></td></tr>@endforeach</tbody>
        </table></div>
    @endif

    <p class="mt-6 text-xs text-slate-500">Keep the batch ID with the audit trail. Repeating the same confirmation is safe and does not duplicate charges.</p>
</div>
@endsection
