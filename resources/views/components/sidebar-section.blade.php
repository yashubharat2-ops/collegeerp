@props(['title'])
<details class="sidebar-section group" data-sidebar-section>
    <summary class="flex cursor-pointer items-center rounded-xl transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-300">
        <div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">{{ $title }}</div>
        <span class="ml-auto mr-3 text-slate-500 transition-transform group-open:rotate-90" aria-hidden="true">▸</span>
    </summary>
    <div data-sidebar-links>
        {{ $slot }}
    </div>
</details>
