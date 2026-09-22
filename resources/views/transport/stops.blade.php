@extends('layouts.app')
@section('title', 'Stops')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Stops</h2>
            <p class="panel-subtitle">All stops of the active college across routes, ordered by route then sequence. Stops always belong to exactly one route — manage them on the route's own stops page.</p>
        </div>
        <div class="flex gap-2">
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-routes.index') }}">Routes</a>
        </div>
    </div>
    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
        <label>Search<input class="input" name="search" value="{{ request('search') }}" placeholder="Name, code or landmark"></label>
        <label>Route<select class="input" name="route_id"><option value="">All routes</option>@foreach($routes as $route)<option value="{{ $route->id }}" @selected((string) request('route_id') === (string) $route->id)>{{ $route->name }}</option>@endforeach</select></label>
        <label>Status<select class="input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <button class="button">Filter</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-stops.list') }}">Reset</a>
    </form>
    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-slate-500"><tr>
                <th class="whitespace-nowrap px-3 py-3">Route</th>
                <th class="whitespace-nowrap px-3 py-3">Name</th>
                <th class="whitespace-nowrap px-3 py-3">Code</th>
                <th class="whitespace-nowrap px-3 py-3">Sequence</th>
                <th class="whitespace-nowrap px-3 py-3">Pickup</th>
                <th class="whitespace-nowrap px-3 py-3">Drop</th>
                <th class="whitespace-nowrap px-3 py-3">Landmark</th>
                <th class="whitespace-nowrap px-3 py-3">Status</th>
                <th class="px-3 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse($records as $record)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->route?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->name }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->code }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->sequence }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->pickup_time ? substr((string) $record->pickup_time, 0, 5) : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->drop_time ? substr((string) $record->drop_time, 0, 5) : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->landmark ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ ucfirst($record->status) }}</td>
                        <td class="px-3 py-3"><div class="flex gap-2">
                            @can('create', \App\Models\TransportStop::class)<a class="button" href="{{ route('transport-stops.create', ['transport_route' => $record->route_id]) }}">Add to route</a>@endcan
                            @can('update', $record)<a class="button" href="{{ route('transport-stops.edit', ['transport_route' => $record->route_id, 'record' => $record->id]) }}">Edit</a>@endcan
                            @can('delete', $record)
                                <form method="POST" action="{{ route('transport-stops.destroy', ['transport_route' => $record->route_id, 'record' => $record->id]) }}" onsubmit="return confirm('Archive this stop?')">@csrf @method('DELETE')<button class="button !bg-red-600">Delete</button></form>
                            @endcan
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-slate-500">No stops found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $records->links() }}</div>
</div>
@endsection
