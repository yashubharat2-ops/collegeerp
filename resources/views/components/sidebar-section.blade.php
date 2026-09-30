@props(['title', 'icon' => 'grid'])
<details class="sidebar-section group" data-sidebar-section>
    <summary class="flex min-h-11 w-full cursor-pointer items-center gap-1.5 rounded-lg px-2 text-slate-300 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-400">
        <x-sidebar-icon :name="$icon" class="sidebar-section-icon text-slate-400 group-open:text-indigo-200" />
        <div class="sidebar-section-label min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-sm font-medium uppercase tracking-widest group-open:text-white">{{ $title }}</div>
        <svg class="sidebar-section-chevron h-3.5 w-3.5 shrink-0 text-slate-500 transition-transform duration-150 group-open:rotate-90 group-open:text-indigo-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
    </summary>
    <div data-sidebar-links>
        {{ $slot }}
    </div>
</details>
