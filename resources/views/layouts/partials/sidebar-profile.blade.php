<div class="sidebar-profile mx-3 mb-3 flex shrink-0 items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5">
    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-500/20 text-sm font-semibold text-indigo-200" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
    <div class="sidebar-profile-info min-w-0 flex-1">
        <p class="truncate text-sm font-medium leading-tight text-white">{{ auth()->user()->name }}</p>
        <p class="mt-0.5 truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
    </div>
</div>
