<div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Email Logs</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Delivered</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['delivered'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">Sent</p>
        <p class="mt-1 text-2xl font-bold text-sky-900">{{ number_format($totals['sent'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Queued</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['queued'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-rose-200 bg-rose-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-rose-700">Failed</p>
        <p class="mt-1 text-2xl font-bold text-rose-900">{{ number_format($totals['failed'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Timestamp</th>
                    <th class="px-4 py-3">Recipient</th>
                    <th class="px-4 py-3">Subject &amp; Content</th>
                    <th class="px-4 py-3">Template</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Provider Ref / Failure</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $log)
                @php
                    $recipientKey = ($log->recipient_type && $log->recipient_id)
                        ? ($log->recipient_type . ':' . $log->recipient_id)
                        : null;
                    $linkedLabel = $recipientKey ? ($recipientLabels[$recipientKey] ?? null) : null;
                @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>Created: {{ $log->created_at?->format('Y-m-d H:i') }}</div>
                        @if($log->sent_at)
                            <div>Sent: {{ $log->sent_at->format('Y-m-d H:i') }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-mono text-xs font-semibold text-slate-900">{{ $log->recipient }}</div>
                        @if($linkedLabel)
                            <div class="text-xs text-slate-500">{{ $linkedLabel }} ({{ $log->recipientTypeLabel() }})</div>
                        @elseif($log->recipient_type)
                            <div class="text-xs text-slate-500">{{ $log->recipientTypeLabel() }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $log->subject ?? '—' }}</div>
                        <div class="line-clamp-2 max-w-md text-xs text-slate-600">{{ $log->content }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">
                        @if($log->template)
                            <div class="font-medium text-slate-900">{{ $log->template->name }}</div>
                            <div class="font-mono text-xs text-slate-500">{{ $log->template->code }}</div>
                        @else
                            <span class="text-xs text-slate-400">Ad-hoc</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $log->status === 'delivered' ? 'bg-emerald-100 text-emerald-800' : ($log->status === 'failed' ? 'bg-rose-100 text-rose-800' : ($log->status === 'sent' ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-800')) }}">
                            {{ $log->statusLabel() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        @if($log->provider_reference)
                            <div class="font-mono">{{ $log->provider_reference }}</div>
                        @endif
                        @if($log->failure_reason)
                            <div class="text-rose-700">{{ $log->failure_reason }}</div>
                        @endif
                        @if(!$log->provider_reference && !$log->failure_reason)
                            —
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">No email logs match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('communication.reports._pagination', ['rows' => $rows])
</div>
