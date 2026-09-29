<div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Notices</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Published</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['published'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-teal-200 bg-teal-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-700">Currently Live</p>
        <p class="mt-1 text-2xl font-bold text-teal-900">{{ number_format($totals['live'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Draft</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['draft'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Archived</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ number_format($totals['archived'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Notice Title</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Priority</th>
                    <th class="px-4 py-3">Target Audience</th>
                    <th class="px-4 py-3">Publish Window</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Created By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $notice)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $notice->title }}</div>
                        <div class="text-xs text-slate-500">{{ $notice->slug }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">{{ $notice->typeLabel() }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $notice->priority === 'urgent' ? 'bg-rose-100 text-rose-800' : ($notice->priority === 'important' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">
                            {{ \App\Domain\Communication\Support\CommunicationPriority::label($notice->priority) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-700">
                        {{ $targetLabels[$notice->id] ?? \App\Domain\Communication\Support\CommunicationTargets::label($notice->target_type) }}
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        <div>Publish: {{ $notice->publish_at?->format('Y-m-d H:i') ?? 'Immediate' }}</div>
                        <div>Expires: {{ $notice->expires_at?->format('Y-m-d H:i') ?? 'No expiry' }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $notice->status === 'published' ? 'bg-emerald-100 text-emerald-800' : ($notice->status === 'draft' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">
                            {{ \App\Domain\Communication\Support\PublicationWorkflow::label($notice->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600">{{ $notice->creator?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No notices match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('communication.reports._pagination', ['rows' => $rows])
</div>
