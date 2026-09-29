<div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Templates</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Active</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['active'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Inactive</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ number_format($totals['inactive'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">SMS Templates</p>
        <p class="mt-1 text-2xl font-bold text-sky-900">{{ number_format($totals['sms'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Email Templates</p>
        <p class="mt-1 text-2xl font-bold text-indigo-900">{{ number_format($totals['email'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Template Name</th>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Channel</th>
                    <th class="px-4 py-3">Subject / Body</th>
                    <th class="px-4 py-3">Placeholders</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Usage (Total / Delivered / Failed)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $template)
                @php $placeholders = $template->placeholders(); @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $template->name }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $template->code }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $template->channel === 'sms' ? 'bg-sky-100 text-sky-800' : 'bg-indigo-100 text-indigo-800' }}">
                            {{ $template->channelLabel() }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($template->subject)
                            <div class="font-medium text-slate-800">{{ $template->subject }}</div>
                        @endif
                        <div class="line-clamp-2 max-w-md text-xs text-slate-600">{{ $template->body }}</div>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        {{ !empty($placeholders) ? implode(', ', $placeholders) : 'None' }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $template->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ ucfirst($template->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-700">
                        <span class="font-semibold text-slate-900">{{ number_format($template->logs_count ?? 0) }}</span> total ·
                        <span class="text-emerald-700">{{ number_format($template->delivered_logs_count ?? 0) }}</span> delivered ·
                        <span class="text-rose-700">{{ number_format($template->failed_logs_count ?? 0) }}</span> failed
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No communication templates match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('communication.reports._pagination', ['rows' => $rows])
</div>
