<p class="panel-subtitle">Custody history comes from existing InventoryAssignment rows. A later assignment is a new row, so returned history remains visible.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1120px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Asset / serial</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Assignee</th><th class="px-3 py-3">Purpose</th><th class="px-3 py-3">Assigned on</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Returned on</th><th class="px-3 py-3">Returned by</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($assignments as $assignment)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $assignment->item?->name ?? 'Unknown asset' }}{{ $assignment->item?->trashed() ? ' (archived)' : '' }}<span class="block font-mono text-xs text-slate-500">{{ $assignment->item?->serial_number ?? $assignment->item?->code ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $assignment->item?->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($assignment->assigned_to_type) }}: {{ $assignment->assigneeName() }}</td>
                    <td class="px-3 py-3">{{ $assignment->purpose ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $assignment->assigned_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($assignment->status) }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $assignment->returned_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $assignment->returner?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No asset assignments match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $assignments->firstItem() ?? 0 }}–{{ $assignments->lastItem() ?? 0 }} of {{ $assignments->total() }} assignments.</span>
    {{ $assignments->links() }}
</div>
