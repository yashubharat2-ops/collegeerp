{{-- Shared status pill. $status is a short machine value; output is escaped. --}}
@php
    $tone = match ($status) {
        'available', 'active', 'returned' => 'bg-emerald-100 text-emerald-700',
        'issued' => 'bg-indigo-100 text-indigo-700',
        'lost', 'suspended' => 'bg-rose-100 text-rose-700',
        'damaged', 'expired' => 'bg-amber-100 text-amber-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp
<span class="rounded-full px-3 py-1 text-xs font-semibold {{ $tone }}">{{ ucfirst($status) }}</span>
