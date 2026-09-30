<div class="sidebar-brand flex shrink-0 items-center gap-3 px-4 pb-4 pt-5">
    @if($institutionBrand['has_logo'] ?? false)
        <img class="sidebar-brand-mark h-10 w-10 shrink-0 rounded-xl bg-white object-contain p-1" src="{{ route('admin.institution-settings.logo') }}" alt="Institution logo">
    @else
        <span class="sidebar-brand-mark grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-600 text-xs font-bold tracking-wide text-white" aria-hidden="true">ERP</span>
    @endif
    <div class="sidebar-brand-info min-w-0 flex-1">
        <p class="truncate text-[15px] font-semibold leading-tight text-white">{{ $institutionBrand['short_name'] ?? config('app.name', 'College ERP') }}</p>
        <p class="mt-0.5 text-xs text-slate-400">College ERP</p>
    </div>
    <button type="button" data-sidebar-header-toggle data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar (Alt+Ctrl+Z)" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-400">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" /></svg>
    </button>
</div>
