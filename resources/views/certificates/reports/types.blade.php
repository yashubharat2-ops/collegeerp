<div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Certificate Types</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total_types'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Requests</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['total_requests'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700">Requested</p>
        <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totals['requested'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Generated</p>
        <p class="mt-1 text-2xl font-bold text-indigo-900">{{ number_format($totals['generated'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Issued</p>
        <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totals['issued'] ?? 0) }}</p>
    </div>
    <div class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">Verified</p>
        <p class="mt-1 text-2xl font-bold text-sky-900">{{ number_format($totals['verified'] ?? 0) }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Certificate Type</th>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Templates</th>
                    <th class="px-4 py-3">Total Requests</th>
                    <th class="px-4 py-3">Requested</th>
                    <th class="px-4 py-3">Generated</th>
                    <th class="px-4 py-3">Issued</th>
                    <th class="px-4 py-3">Verified</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($typeRows as $typeRow)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900">{{ $typeRow->name }}</div>
                        @if($typeRow->description)
                            <div class="text-xs text-slate-500">{{ $typeRow->description }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $typeRow->code }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ $typeRow->builtin_key ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ $typeRow->builtin_key ? 'Built-in' : 'Custom' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-700">{{ number_format((int) ($typeRow->templates_count ?? 0)) }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ number_format((int) ($typeRow->total_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-amber-800">{{ number_format((int) ($typeRow->requested_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-indigo-800">{{ number_format((int) ($typeRow->generated_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-emerald-800">{{ number_format((int) ($typeRow->issued_count ?? 0)) }}</td>
                    <td class="px-4 py-3 text-sky-800">
                        {{ number_format((int) ($typeRow->verified_count ?? 0)) }}
                        @if((int) ($typeRow->verification_lookups_sum ?? 0) > 0)
                            <span class="text-xs text-slate-500">({{ number_format((int) $typeRow->verification_lookups_sum) }} lookups)</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-10 text-center text-sm text-slate-500">No certificate types match the selected filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
