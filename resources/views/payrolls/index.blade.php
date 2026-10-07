@extends('layouts.app')
@section('title', 'Staff Salary / Payroll')
@section('content')<div class="panel" data-bulk-scope><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="panel-title">Staff Salary / Payroll</h2><p class="panel-subtitle">Monthly server-side payroll snapshots. No accounting or bank postings are created.</p></div>@can('process', App\Models\Payroll::class)<a class="button" href="{{ route('payrolls.create') }}">+ Process payroll</a>@endcan</div><form method="GET" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><select class="input" name="faculty_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((int)($filters['faculty_id'] ?? 0) === $employee->id)>{{ $employee->full_name }}</option>@endforeach</select><input class="input" type="month" name="pay_period" value="{{ $filters['pay_period'] ?? '' }}"><select class="input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><button class="button" type="submit">Filter</button></form>
    {{-- Bulk selection over the filtered payroll records list. Export only — the shared
         handler re-queries every ticked id inside the active college and re-authorizes
         each record before the CSV endpoint streams. --}}
    <x-list.bulk-selection-bar module="payrolls">
        @if(auth()->user()?->hasPermission('payrolls.view'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export selected
            </button>
        @endif
    </x-list.bulk-selection-bar>

<div class="mt-8 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="w-10 py-3"><x-list.select-all /></th><th class="py-3">Pay period</th><th>Employee</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Status</th><th></th></tr></thead><tbody>@forelse($payrolls as $payroll)<tr class="border-b"><td class="py-3"><x-list.row-checkbox :id="$payroll->id" /></td><td class="py-3">{{ $payroll->pay_period?->format('M Y') }}</td><td class="font-medium">{{ $payroll->employee?->full_name }}</td><td>{{ number_format((float)$payroll->gross_amount, 2) }}</td><td>{{ number_format((float)$payroll->total_deductions, 2) }}</td><td>{{ number_format((float)$payroll->net_amount, 2) }}</td><td>{{ ucfirst($payroll->status) }}</td><td class="text-right"><a class="text-xs text-indigo-600" href="{{ route('payrolls.show', $payroll) }}">View</a></td></tr>@empty<tr><td colspan="8" class="py-6 text-slate-500">No payroll records found.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $payrolls->links() }}</div></div>@endsection
