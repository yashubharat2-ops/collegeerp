@props(['title'])
<details class="sidebar-section group" data-sidebar-section>
    <summary class="flex min-h-9 w-full cursor-pointer items-center gap-2 rounded-lg px-3 text-slate-400 transition group-open:text-indigo-300 hover:bg-white/10 hover:text-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-400">
        <div class="min-w-0 flex-1 text-xs font-semibold uppercase tracking-widest">{{ $title }}</div>
        <svg class="h-4 w-4 shrink-0 transition-transform duration-150 group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
    </summary>
    <div data-sidebar-links>
        {{ $slot }}
    </div>
</details>
