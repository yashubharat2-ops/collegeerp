<p class="panel-subtitle">Returns are the returned status, return date, actor and notes already stored on InventoryAssignment. No separate return record is created.</p>
<div class="mt-4 overflow-x-auto">
    <table class="w-full min-w-[1040px] text-left text-sm">
        <thead class="border-b bg-slate-50 text-slate-500"><tr>
            <th class="px-3 py-3">Asset / serial</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Assignee</th><th class="px-3 py-3">Assigned on</th><th class="px-3 py-3">Returned on</th><th class="px-3 py-3">Return status</th><th class="px-3 py-3">Return notes</th><th class="px-3 py-3">Received by</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($returns as $assignment)
                <tr>
                    <td class="px-3 py-3 font-medium">{{ $assignment->item?->name ?? 'Unknown asset' }}{{ $assignment->item?->trashed() ? ' (archived)' : '' }}<span class="block font-mono text-xs text-slate-500">{{ $assignment->item?->serial_number ?? $assignment->item?->code ?? '—' }}</span></td>
                    <td class="px-3 py-3">{{ $assignment->item?->category?->name ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $assignment->assigneeName() }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $assignment->assigned_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap">{{ $assignment->returned_on?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3">{{ ucfirst($assignment->status) }}</td>
                    <td class="px-3 py-3">{{ $assignment->return_notes ?? '—' }}</td>
                    <td class="px-3 py-3">{{ $assignment->returner?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No asset returns match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="no-print mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Showing {{ $returns->firstItem() ?? 0 }}–{{ $returns->lastItem() ?? 0 }} of {{ $returns->total() }} returned assets.</span>
    {{ $returns->links() }}
</div>
