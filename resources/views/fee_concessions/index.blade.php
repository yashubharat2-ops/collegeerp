@extends('layouts.app')

@section('title', 'Fee Discounts / Concessions')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Discounts / Concessions</h2>
            <p class="panel-subtitle">Amounts are computed on the server from the assigned fee. Approval is a separate permission and is recorded with the approver and time.</p>
        </div>
        @can('create', App\Models\FeeConcession::class)
            <a class="button" href="{{ route('fee-concessions.create') }}">+ Add concession</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6" method="GET" action="{{ route('fee-concessions.index') }}">
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
            <label class="label" for="type">Type</label>
            <select class="input" id="type" name="type">
                <option value="">All types</option>
                @foreach($concessionTypes as $type)
                    <option value="{{ $type }}" @selected($selected['type'] === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($concessionStatuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($selected['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Student</th>
                    <th>Fee Structure</th>
                    <th>Type</th>
                    <th class="text-right">Value</th>
                    <th class="text-right">Amount</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Approved</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($concessions as $concession)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $concession->studentFeeAssignment?->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $concession->studentFeeAssignment?->feeStructure?->name ?? '—' }}</td>
                        <td>{{ ucfirst($concession->type) }}</td>
                        <td class="text-right">{{ $concession->type === 'percentage' ? number_format((float) $concession->value, 2).'%' : number_format((float) $concession->value, 2) }}</td>
                        <td class="text-right font-semibold">{{ number_format((float) $concession->amount, 2) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($concession->reason ?? '—', 40) }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $concession->status === 'approved' ? 'bg-emerald-100 text-emerald-700' : ($concession->status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">{{ ucfirst($concession->status) }}</span>
                        </td>
                        <td>
                            @if($concession->isApproved())
                                {{ $concession->approved_at?->format('Y-m-d') }}<br>
                                <span class="text-xs text-slate-500">{{ $concession->approver?->name ?? '—' }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @if($concession->status !== 'approved' && $concession->status !== 'rejected' && $concession->status !== 'cancelled')
                                    @can('approve', $concession)
                                        <form method="POST" action="{{ route('fee-concessions.approve', $concession) }}" onsubmit="return confirm(@js('Approve a concession of '.number_format((float) $concession->amount, 2).'?'));">
                                            @csrf
                                            <button class="button !bg-emerald-100 !text-emerald-700" type="submit">Approve</button>
                                        </form>
                                    @endcan
                                @endif
                                @can('update', $concession)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.edit', $concession) }}">Edit</a>
                                @endcan
                                @can('delete', $concession)
                                    <form method="POST" action="{{ route('fee-concessions.destroy', $concession) }}" onsubmit="return confirm('Delete this concession? The audit trail keeps the row.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No concessions recorded for this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $concessions->links() }}</div>
</div>
@endsection
