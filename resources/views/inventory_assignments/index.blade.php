@extends('layouts.app')

@section('title', 'Asset Assignment')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Asset Assignment</h2>
            <p class="panel-subtitle">Custody history of individual assets. An asset has at most one active assignment at a time; returning it (Asset Return) preserves this row, and a later re-assignment is a new row.</p>
        </div>
        @if(auth()->user()?->hasPermission('inventory_assignments.create'))
            <a class="button" href="{{ route('inventory-assignments.create') }}">+ Assign asset</a>
        @endif
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-assignments.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="item_id">Asset</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All assets</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                <option value="active" @selected($filters['status'] === 'active')>Active (out)</option>
                <option value="returned" @selected($filters['status'] === 'returned')>Returned</option>
            </select>
        </div>
        <div>
            <label class="label" for="assigned_to_type">Assignee</label>
            <select class="input" id="assigned_to_type" name="assigned_to_type">
                <option value="">All assignees</option>
                <option value="student" @selected($filters['assigned_to_type'] === 'student')>Student</option>
                <option value="faculty" @selected($filters['assigned_to_type'] === 'faculty')>Staff</option>
            </select>
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Asset</th>
                    <th>Serial</th>
                    <th>Assignee</th>
                    <th>Purpose</th>
                    <th>Assigned on</th>
                    <th>Returned</th>
                    <th>Status</th>
                    <th>Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assignments as $assignment)
                    <tr class="border-b">
                        <td class="font-medium">
                            {{ $assignment->item?->name ?? '—' }}
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ $assignment->item?->code }}</span>
                        </td>
                        <td class="font-mono text-xs text-slate-600">{{ $assignment->item?->serial_number ?? '—' }}</td>
                        <td>
                            <span class="font-medium">{{ $assignment->assigneeName() }}</span>
                            <span class="block text-xs text-slate-500">{{ $assignment->assigned_to_type === 'student' ? 'Student' : 'Staff' }}</span>
                        </td>
                        <td class="text-xs text-slate-600">{{ $assignment->purpose ?? '—' }}</td>
                        <td>{{ $assignment->assigned_on->format('d M Y') }}</td>
                        <td>
                            @if($assignment->isReturned())
                                {{ $assignment->returned_on?->format('d M Y') }}
                                <span class="block text-xs text-slate-500">by {{ $assignment->returner?->name ?? '—' }}</span>
                                @if($assignment->return_notes)
                                    <span class="block text-xs text-slate-500">{{ $assignment->return_notes }}</span>
                                @endif
                            @else
                                <span class="text-slate-500">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $assignment->isActive() ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                                {{ $assignment->isActive() ? 'Active' : 'Returned' }}
                            </span>
                        </td>
                        <td class="text-slate-600">{{ $assignment->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No assignments recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $assignments->links() }}</div>
</div>
@endsection
