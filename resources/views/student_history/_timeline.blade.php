@php
    $categoryStyles = [
        'admission' => 'bg-violet-100 text-violet-700',
        'student' => 'bg-indigo-100 text-indigo-700',
        'enrollment' => 'bg-sky-100 text-sky-700',
        'academic' => 'bg-emerald-100 text-emerald-700',
        'promotion' => 'bg-amber-100 text-amber-700',
        'transfer' => 'bg-rose-100 text-rose-700',
        'document' => 'bg-slate-200 text-slate-700',
        'audit' => 'bg-slate-100 text-slate-600',
    ];
@endphp

<ol class="mt-4 space-y-4 border-l border-slate-200 pl-5">
    @forelse($events as $event)
        <li class="relative">
            <span class="absolute -left-[26px] top-1.5 h-2.5 w-2.5 rounded-full bg-indigo-400"></span>
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-medium text-slate-800">{{ $event->label }}</p>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $categoryStyles[$event->category] ?? 'bg-slate-100 text-slate-600' }}">
                    {{ ucfirst($event->category) }}
                </span>
                @if($event->source === 'audit')
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500" title="From the append-only audit log">audited</span>
                @endif
            </div>
            <p class="text-xs text-slate-500">{{ $event->occurredAtTime() }}</p>
            @if($event->description !== '')
                <p class="text-sm text-slate-600">{{ $event->description }}</p>
            @endif
            @if($event->reference)
                <p class="text-[11px] text-slate-400">{{ $event->reference }}</p>
            @endif
        </li>
    @empty
        <li class="text-sm text-slate-500">No history recorded yet.</li>
    @endforelse
</ol>
