@extends('layouts.app')

@section('title', 'Item Issue / Allocation')

@section('content')
<div class="panel" data-bulk-scope>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Item Issue / Allocation</h2>
            <p class="panel-subtitle">Consumable stock issued to students and staff. Each issue reduces stock through the existing stock ledger (a stock-out transaction whose reference is the issue number). Issues are append-only — returned stock is recorded as a new incoming movement.</p>
        </div>
        @if(auth()->user()?->hasPermission('inventory_issues.create'))
            <a class="button" href="{{ route('inventory-issues.create') }}">+ Record issue</a>
        @endif
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" method="GET" action="{{ route('inventory-issues.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="item_id">Item</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All items</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="issued_to_type">Recipient</label>
            <select class="input" id="issued_to_type" name="issued_to_type">
                <option value="">All recipients</option>
                <option value="student" @selected($filters['issued_to_type'] === 'student')>Student</option>
                <option value="faculty" @selected($filters['issued_to_type'] === 'faculty')>Staff</option>
            </select>
        </div>
        <div>
            <label class="label" for="from">From</label>
            <input class="input" id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input class="input" id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    {{-- Bulk selection over the filtered item-issues list. Export only — the shared
         handler re-queries every ticked id inside the active college and re-authorizes
         each record before the CSV endpoint streams. --}}
    <x-list.bulk-selection-bar module="inventory_issues">
        @if(auth()->user()?->hasPermission('inventory_issues.view'))<button type="button" data-bulk-action="export" class="button !py-2 !text-xs font-semibold">Export selected</button>@endif
    </x-list.bulk-selection-bar>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="w-10 py-2"><x-list.select-all /></th>
                    <th class="py-2">Date</th>
                    <th>Issue #</th>
                    <th>Item</th>
                    <th class="text-right">Quantity</th>
                    <th>Recipient</th>
                    <th>Purpose / Reference</th>
                    <th>Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse($issues as $issue)
                    <tr class="border-b">
                        <td class="py-2"><x-list.row-checkbox :id="$issue->id" /></td>
                        <td class="py-2">{{ $issue->movement_date->format('d M Y') }}</td>
                        <td class="font-mono text-xs text-indigo-700">{{ $issue->number }}</td>
                        <td class="font-medium">
                            {{ $issue->item?->name ?? '—' }}
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ $issue->item?->code }}</span>
                        </td>
                        <td class="text-right font-mono text-xs text-rose-700">
                            −{{ $issue->quantity }}
                            <span class="text-slate-500">{{ $issue->item?->unit }}</span>
                        </td>
                        <td>
                            <span class="font-medium">{{ $issue->recipientName() }}</span>
                            <span class="block text-xs text-slate-500">{{ $issue->issued_to_type === 'student' ? 'Student' : 'Staff' }}</span>
                        </td>
                        <td class="text-xs text-slate-600">
                            {{ $issue->purpose ?? '—' }}
                            @if($issue->reference)
                                <span class="block text-slate-500">Ref: {{ $issue->reference }}</span>
                            @endif
                        </td>
                        <td class="text-slate-600">{{ $issue->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No issues recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $issues->links() }}</div>
</div>
@endsection
