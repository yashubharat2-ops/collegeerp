<div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tracked Notifications</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Pending</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['pending'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">Sent</p>
        <p class="mt-1 text-2xl font-bold text-sky-900">{{ number_format($totals['sent'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-teal-200 bg-teal-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-700">Delivered</p>
        <p class="mt-1 text-2xl font-bold text-teal-900">{{ number_format($totals['delivered'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Read</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['read'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Outbound Delivered / Failed</p>
        <p class="mt-1 text-lg font-bold text-slate-900">
            <span class="text-emerald-700">{{ number_format($logTotals['delivered'] ?? 0) }}</span> /
            <span class="text-rose-700">{{ number_format($logTotals['failed'] ?? 0) }}</span>
        </p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Notification</th>
                    <th class="px-4 py-3">Recipient</th>
                    <th class="px-4 py-3">Delivery State</th>
                    <th class="px-4 py-3">Sent At</th>
                    <th class="px-4 py-3">Delivered At</th>
                    <th class="px-4 py-3">Read At</th>
                    <th class="px-4 py-3">Created By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $notification)
                @php
                    $recipientKey = $notification->recipient_type . ':' . $notification->recipient_id;
                    $recipientLabel = $recipientLabels[$recipientKey] ?? ('#' . $notification->recipient_id);
                    $state = $notification->deliveryState();
                @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $notification->title }}</div>
                        <div class="text-xs text-slate-500">{{ $notification->typeLabel() }} · {{ \App\Domain\Communication\Support\CommunicationPriority::label($notification->priority) }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $recipientLabel }}</div>
                        <div class="text-xs text-slate-500">{{ $notification->recipientTypeLabel() }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $state === 'read' ? 'bg-emerald-100 text-emerald-800' : ($state === 'delivered' ? 'bg-teal-100 text-teal-800' : ($state === 'sent' ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-800')) }}">
                            {{ $notification->deliveryStateLabel() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $notification->sent_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $notification->delivered_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $notification->read_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $notification->creator?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No delivery or read tracking records match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('communication.reports._pagination', ['rows' => $rows])
</div>
