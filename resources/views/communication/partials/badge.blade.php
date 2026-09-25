{{--
    Consistent status / priority / visibility / read-state badges for the
    Communication module. The class maps live in this Blade file on purpose so
    Tailwind's source scanner sees every class. Output is escaped with {{ }}.

    @param string $kind   status | priority | visibility | read | channel | log | delivery
    @param string $value  the raw value
    @param string $label  optional display label
--}}
@php
    $communicationBadgeStyles = [
        'status' => [
            'draft' => 'bg-slate-100 text-slate-700',
            'published' => 'bg-emerald-100 text-emerald-700',
            'archived' => 'bg-amber-100 text-amber-800',
        ],
        'priority' => [
            'normal' => 'bg-slate-100 text-slate-700',
            'important' => 'bg-amber-100 text-amber-800',
            'urgent' => 'bg-rose-100 text-rose-700',
        ],
        'visibility' => [
            'draft' => 'bg-slate-100 text-slate-700',
            'archived' => 'bg-amber-100 text-amber-800',
            'scheduled' => 'bg-sky-100 text-sky-700',
            'live' => 'bg-emerald-100 text-emerald-700',
            'expired' => 'bg-slate-200 text-slate-600',
        ],
        'read' => [
            'read' => 'bg-slate-100 text-slate-600',
            'unread' => 'bg-indigo-100 text-indigo-700',
        ],
        // Communication Phase 2 — channels, log statuses and tracking states.
        'channel' => [
            'sms' => 'bg-violet-100 text-violet-700',
            'email' => 'bg-sky-100 text-sky-700',
        ],
        'log' => [
            'queued' => 'bg-slate-100 text-slate-700',
            'sent' => 'bg-sky-100 text-sky-700',
            'delivered' => 'bg-emerald-100 text-emerald-700',
            'failed' => 'bg-rose-100 text-rose-700',
        ],
        'delivery' => [
            'pending' => 'bg-slate-200 text-slate-600',
            'sent' => 'bg-sky-100 text-sky-700',
            'delivered' => 'bg-emerald-100 text-emerald-700',
            'read' => 'bg-indigo-100 text-indigo-700',
        ],
        'template' => [
            'active' => 'bg-emerald-100 text-emerald-700',
            'inactive' => 'bg-slate-200 text-slate-600',
        ],
    ];
    $communicationBadgeClass = $communicationBadgeStyles[$kind][$value] ?? 'bg-slate-100 text-slate-700';
@endphp
<span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold {{ $communicationBadgeClass }}">{{ $label ?? ucfirst((string) $value) }}</span>
