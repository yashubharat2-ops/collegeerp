<div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Notifications</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Unread</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['unread'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Read</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['read'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-teal-200 bg-teal-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-700">Delivered</p>
        <p class="mt-1 text-2xl font-bold text-teal-900">{{ number_format($totals['delivered'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Pending</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ number_format($totals['pending'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Notification</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Priority</th>
                    <th class="px-4 py-3">Recipient</th>
                    <th class="px-4 py-3">Read Status</th>
                    <th class="px-4 py-3">Created At</th>
                    <th class="px-4 py-3">Created By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $notification)
                @php
                    $recipientKey = $notification->recipient_type . ':' . $notification->recipient_id;
                    $recipientLabel = $recipientLabels[$recipientKey] ?? ('#' . $notification->recipient_id);
                @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $notification->title }}</div>
                        <div class="line-clamp-2 max-w-md text-xs text-slate-600">{{ $notification->message }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">{{ $notification->typeLabel() }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $notification->priority === 'urgent' ? 'bg-rose-100 text-rose-800' : ($notification->priority === 'important' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">
                            {{ \App\Domain\Communication\Support\CommunicationPriority::label($notification->priority) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $recipientLabel }}</div>
                        <div class="text-xs text-slate-500">{{ $notification->recipientTypeLabel() }}</div>
                    </td>
                    <td class="px-4 py-3">
                        @if($notification->isRead())
                            <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Read</span>
                            <div class="mt-0.5 text-xs text-slate-500">{{ $notification->read_at?->format('Y-m-d H:i') }}</div>
                        @else
                            <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Unread</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $notification->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ $notification->creator?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No notifications match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('communication.reports._pagination', ['rows' => $rows])
</div>
