<div class="mb-5 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500">
    <p>Administration / Settings <span aria-hidden="true">·</span> {{ app(\App\Support\Tenancy\TenantContext::class)->college()?->name ?? 'Platform' }}</p>
    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">{{ ($platform ?? false) ? 'Explicit platform scope' : 'Active college scope' }}</span>
</div>
