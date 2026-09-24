@extends('layouts.app')

@section('title', 'Hostel Dashboard')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Hostel Dashboard</h2>
                <p class="panel-subtitle">Live, read-only overview of the active college's hostel infrastructure — hostels, buildings / blocks, rooms and beds. Figures are computed from the masters themselves; nothing here is stored separately. Archived records are excluded.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('viewAny', App\Models\Hostel::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostels.index') }}">Hostels</a>
                @endcan
                @can('viewAny', App\Models\HostelBuilding::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-buildings.index') }}">Buildings</a>
                @endcan
                @can('viewAny', App\Models\HostelRoom::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-rooms.index') }}">Rooms</a>
                @endcan
                @can('viewAny', App\Models\HostelBed::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-beds.index') }}">Beds</a>
                @endcan
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Total Hostels</p>
                <p class="stat-value">{{ $totalHostels }}</p>
                <p class="stat-hint">{{ $activeHostels }} active</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Buildings / Blocks</p>
                <p class="stat-value">{{ $totalBuildings }}</p>
                <p class="stat-hint">across {{ $totalHostels === 1 ? '1 hostel' : $totalHostels.' hostels' }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Rooms</p>
                <p class="stat-value">{{ $totalRooms }}</p>
                <p class="stat-hint">in {{ $totalBuildings === 1 ? '1 building' : $totalBuildings.' buildings' }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Beds</p>
                <p class="stat-value">{{ $totalBeds }}</p>
                <p class="stat-hint">{{ $totals['inactive_beds'] }} marked inactive</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs text-emerald-700">Available beds</p>
                <p class="text-xl font-bold text-emerald-800">{{ $availableBeds }}</p>
                <p class="mt-1 text-xs text-emerald-700">
                    {{ $totalBeds > 0 ? number_format(100 * $availableBeds / $totalBeds, 1).'% of all beds' : 'no beds recorded yet' }}
                </p>
            </div>
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                <p class="text-xs text-indigo-700">Occupied beds</p>
                <p class="text-xl font-bold text-indigo-800">{{ $occupiedBeds }}</p>
                <p class="mt-1 text-xs text-indigo-700">
                    {{ $totalBeds > 0 ? number_format(100 * $occupiedBeds / $totalBeds, 1).'% of all beds' : 'no beds recorded yet' }}
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Live occupancy</p>
                <p class="text-xl font-bold">
                    {{ $totalBeds > 0 ? number_format(100 * $occupiedBeds / $totalBeds, 1).'%' : '—' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">share of beds currently marked occupied</p>
            </div>
        </div>
    </div>

    @if($totalHostels === 0)
        <div class="panel">
            <h3 class="font-semibold">Getting started</h3>
            <p class="mt-2 text-sm text-slate-600">No hostels exist for this college yet. A typical set-up order is: create a <strong>Hostel</strong>, add its <strong>Buildings / Blocks</strong>, then <strong>Rooms</strong>, and finally <strong>Beds</strong>. Hostel allocation, fees, attendance, visitors and reports are separate screens of the Hostel Management module planned for later phases.</p>
        </div>
    @endif

    <div class="panel">
        <h3 class="font-semibold">Occupancy per hostel</h3>
        <p class="panel-subtitle">Every hostel of the active college with its live hierarchy and bed status counts.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Hostel</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Gender</th>
                        <th class="text-right">Buildings</th>
                        <th class="text-right">Rooms</th>
                        <th class="text-right">Beds</th>
                        <th class="text-right">Available</th>
                        <th class="text-right">Occupied</th>
                        <th class="text-right">Occupancy</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($perHostel as $hostel)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ $hostel->name }}</td>
                            <td><span class="font-mono text-xs">{{ $hostel->code }}</span></td>
                            <td>{{ ucfirst($hostel->hostel_type) }}</td>
                            <td>{{ ucfirst($hostel->gender) }}</td>
                            <td class="text-right">{{ $hostel->buildings_count }}</td>
                            <td class="text-right">{{ $hostel->rooms_count }}</td>
                            <td class="text-right">{{ $hostel->beds_count }}</td>
                            <td class="text-right">
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ $hostel->available_beds_count }}</span>
                            </td>
                            <td class="text-right">
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">{{ $hostel->occupied_beds_count }}</span>
                            </td>
                            <td class="text-right text-slate-600">
                                {{ $hostel->beds_count > 0 ? number_format(100 * $hostel->occupied_beds_count / $hostel->beds_count, 1).'%' : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="10">No hostels recorded yet for this college.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
