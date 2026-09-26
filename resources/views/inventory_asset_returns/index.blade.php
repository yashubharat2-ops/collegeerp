@extends('layouts.app')

@section('title', 'Asset Return')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Asset Return</h2>
            <p class="panel-subtitle">Assets currently out, from the active assignment history. Returning an asset preserves its assignment row (status becomes returned, with the return date, actor and notes) and the asset can be assigned again later as a new assignment.</p>
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('inventory-asset-returns.index') }}">
        <div class="lg:col-span-2">
            <label class="label" for="item_id">Asset</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All assets</option>
                @foreach($items as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button w-full sm:w-auto" type="submit">Filter</button>
        </div>
    </form>

    @unless(auth()->user()?->hasPermission('inventory_asset_returns.create'))
        <p class="mt-6 text-xs text-slate-500">
            You can see the assets currently out. Recording returns requires the
            <code>inventory_asset_returns.create</code> permission.
        </p>
    @endunless

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Asset</th>
                    <th>Serial</th>
                    <th>Assignee</th>
                    <th>Purpose</th>
                    <th>Assigned on</th>
                    <th>Assigned by</th>
                    <th>Return</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assignments as $assignment)
                    <tr class="border-b align-top">
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
                        <td class="text-slate-600">{{ $assignment->creator?->name ?? '—' }}</td>
                        <td>
                            @if(auth()->user()?->hasPermission('inventory_asset_returns.create'))
                                <form method="POST" action="{{ route('inventory-asset-returns.store') }}" class="grid gap-2">
                                    @csrf
                                    <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                    <input class="input" name="returned_on" type="date" value="{{ now()->toDateString() }}" required>
                                    <input class="input" name="return_notes" type="text" maxlength="2000" placeholder="Condition / notes (optional)">
                                    <button class="button !bg-emerald-600 text-white" type="submit">Return asset</button>
                                </form>
                            @else
                                <span class="text-slate-500">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No assets are currently out. Nothing to return.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $assignments->links() }}</div>
</div>
@endsection
