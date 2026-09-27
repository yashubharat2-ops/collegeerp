<p class="panel-subtitle">Academic calendar events in date order. A date window includes every event that overlaps it; a term filter also includes year-wide events (no term) of that term's year.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Events</p><p class="text-2xl font-bold text-indigo-900">{{ number_format(array_sum($counts)) }}</p></div>
    @foreach($counts as $status => $total)
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-700">{{ ucfirst($status) }}</p><p class="text-2xl font-bold text-slate-900">{{ number_format($total) }}</p></div>
    @endforeach
</div>
<div class="mt-5 overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead><tr class="border-b text-slate-500"><th class="py-3 pr-4">Dates</th><th class="pr-4">Event</th><th class="pr-4">Type</th><th class="pr-4">Year · term</th><th class="pr-4 text-right">Days</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rows as $event)
            <tr class="border-b align-top">
                <td class="py-3 pr-4 font-medium">{{ $event->start_date?->format('d M Y') ?? '—' }}@if($event->end_date && ! $event->end_date->isSameDay($event->start_date)) – {{ $event->end_date->format('d M Y') }}@endif</td>
                <td class="pr-4">{{ $event->title }}@if($event->description)<span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($event->description, 120) }}</span>@endif</td>
                <td class="pr-4">{{ ucfirst($event->event_type) }}</td>
                <td class="pr-4">{{ $event->academicYear?->name ?? '—' }} · {{ $event->academicTerm?->name ?? 'All terms' }}</td>
                <td class="pr-4 text-right">{{ $event->start_date && $event->end_date ? (int) $event->start_date->diffInDays($event->end_date) + 1 : '—' }}</td>
                <td>{{ ucfirst($event->status) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-slate-500">No calendar events match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('academic_reports._pagination', ['subject' => 'events'])
