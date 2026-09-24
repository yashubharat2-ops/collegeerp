@extends('layouts.app')

@section('title', 'Hostel Allocation Details')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Hostel Allocation — {{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }}</h2>
            <p class="panel-subtitle">Allocation {{ $allocation->id }} — {{ ucfirst($allocation->status) }} since {{ $allocation->allocation_date?->format('d M Y') }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-allocations.index') }}">Back</a>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Student</p>
            <p class="font-semibold">{{ $allocation->studentEnrollment?->student?->first_name }} {{ $allocation->studentEnrollment?->student?->last_name }}</p>
            <p class="text-sm text-slate-600">{{ $allocation->studentEnrollment?->student?->student_number }} — Enrollment {{ $allocation->studentEnrollment?->enrollment_number }}</p>
        </div>
        <div class="rounded-lg bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Academic Year</p>
            <p class="font-semibold">{{ $allocation->academicYear?->name }}</p>
        </div>
        <div class="rounded-lg bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Hostel Hierarchy</p>
            <p class="font-semibold">{{ $allocation->hostel?->name }} — {{ $allocation->building?->name }} — Room {{ $allocation->room?->room_number }} — Bed {{ $allocation->bed?->bed_number }}</p>
            <p class="text-sm text-slate-600">Bed status: {{ ucfirst($allocation->bed?->status ?? '—') }} (synced from allocation)</p>
        </div>
        <div class="rounded-lg bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Dates</p>
            <p>Allocated: {{ $allocation->allocation_date?->format('d M Y') }}</p>
            <p>Vacated: {{ $allocation->vacated_date?->format('d M Y') ?? '—' }}</p>
            <p>Status: <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold">{{ ucfirst($allocation->status) }}</span></p>
        </div>
        <div class="sm:col-span-2 rounded-lg bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Remarks</p>
            <p>{{ $allocation->remarks ?? '—' }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        @can('update', $allocation)
            <a class="button" href="{{ route('hostel-allocations.edit', $allocation) }}">Edit</a>
            @if($allocation->status === 'active')
                <form method="POST" action="{{ route('hostel-allocations.vacate', $allocation) }}">
                    @csrf
                    <input type="hidden" name="vacated_date" value="{{ now()->format('Y-m-d') }}">
                    <button class="button !bg-amber-100 !text-amber-700" type="submit" onclick="return confirm('Vacate this allocation?')">Vacate</button>
                </form>
                <form method="POST" action="{{ route('hostel-allocations.cancel', $allocation) }}">
                    @csrf
                    <button class="button !bg-rose-100 !text-rose-700" type="submit" onclick="return confirm('Cancel this allocation?')">Cancel</button>
                </form>
            @endif
        @endcan
    </div>

    @if($allocation->feeAssignments && $allocation->feeAssignments->count() > 0)
        <div class="mt-8">
            <h3 class="font-semibold">Hostel Fee Assignments</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-slate-500"><tr><th>Structure</th><th>Amount</th><th>Period</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($allocation->feeAssignments as $fa)
                            <tr class="border-b"><td>{{ $fa->feeStructure?->name }}</td><td>{{ number_format((float) $fa->assigned_amount, 2) }}</td><td>{{ $fa->effective_from?->format('d M Y') }} {{ $fa->effective_until ? '→ '.$fa->effective_until->format('d M Y') : '' }}</td><td>{{ ucfirst($fa->status) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
