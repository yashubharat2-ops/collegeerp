@extends('layouts.app')

@section('title', 'Due / Outstanding Fees')

@section('content')
<div class="panel" data-bulk-scope>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Due / Outstanding Fees</h2>
            <p class="panel-subtitle">
                Read-only ledger. Outstanding = assigned fee + valid refunds − valid concessions − valid collections.
                Cancelled payments are excluded and nothing on this screen can be edited.
            </p>
        </div>
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
        :action="route('fee-dues.index')"
        :preserve="['student_id' => request('student_id')]"
        title="Filter Students for Outstanding Fees"
    >
        <x-list.filter-select name="fee_structure_id" label="Fee Structure" :value="$selected['fee_structure_id']" placeholder="All structures">
            @foreach($feeStructures as $structure)
                <option value="{{ $structure->id }}">{{ $structure->name }}</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="student_enrollment_id" label="Enrollment" :value="$selected['student_enrollment_id']" placeholder="All enrollments">
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}">{{ $enrollment->enrollment_number }} · {{ $enrollment->student?->fullName() ?? '—' }}</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="status" label="Due Status" :value="$selected['status']" placeholder="All ledger statuses">
            @foreach($ledgerStatuses as $statusOption)
                <option value="{{ $statusOption }}">{{ ucfirst($statusOption) }}</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="assignment_status" label="Assignment Status" :value="$selected['assignment_status']" placeholder="Payable assignments">
            @foreach($assignmentStatuses as $statusOption)
                <option value="{{ $statusOption }}">{{ ucfirst($statusOption) }}</option>
            @endforeach
        </x-list.filter-select>
    </x-student-filter>
    {{-- Bulk selection over the filtered due rows list. Export only — the shared
         handler re-queries every ticked id inside the active college and re-authorizes
         each record before the CSV endpoint streams. --}}
    <x-list.bulk-selection-bar module="fee_dues">
        @if(auth()->user()?->hasPermission('fee_dues.view'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export selected
            </button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Assignments</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['assignments']) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Assigned</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['assigned'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Concessions</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['concession'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Collected (net)</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['net_collected'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Refunded</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['refunded'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Outstanding</p>
            <p class="mt-1 text-lg font-bold text-rose-700">{{ number_format($totals['outstanding'], 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Student</th>
                    <th class="w-10 py-2"><x-list.select-all /></th>
                    <th>Enrollment</th>
                    <th>Year / Program</th>
                    <th>Fee Structure</th>
                    <th class="text-right">Assigned</th>
                    <th class="text-right">Concession</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Refunded</th>
                    <th class="text-right">Outstanding</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dues as $assignment)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td class="py-2"><x-list.row-checkbox :id="$assignment->id" /></td>
                        <td>{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                        <td>{{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $assignment->studentEnrollment?->program?->code ?? '—' }}</td>
                        <td>{{ $assignment->feeStructure?->name ?? '—' }}@if($assignment->isPerFeeHead())<span class="block text-xs text-indigo-700">Fee Head: {{ $assignment->feeHeadLabel() }}</span>@endif</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['assigned'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['concession'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['paid'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['refunded'], 2) }}</td>
                        <td class="text-right font-semibold">{{ number_format((float) $assignment->ledger['outstanding'], 2) }}</td>
                        <td>
                            @php($status = $assignment->ledger['status'])
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $status === 'paid' ? 'bg-emerald-100 text-emerald-700' : ($status === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') }}">{{ ucfirst($status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('create', App\Models\FeePayment::class)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.create', ['student_fee_assignment_id' => $assignment->id]) }}">Collect</a>
                                @endcan
                                @can('create', App\Models\FeeConcession::class)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.create', ['student_fee_assignment_id' => $assignment->id]) }}">Concession</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="12">No fee assignments match this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $dues->links() }}</div>
</div>
@endsection
