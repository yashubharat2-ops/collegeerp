@extends('layouts.app')
@section('title', 'Vehicle Documents')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Vehicle Documents</h2>
            <p class="panel-subtitle">Private, tenant-scoped documents for existing vehicles (registration, insurance, fitness, permit, PUC and any college-specific type). Files are never served by URL and download through an authorized action.</p>
        </div>
        <div class="flex gap-2">
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('vehicles.index') }}">Vehicles</a>
            @can('create', App\Models\VehicleDocument::class)
                <a class="button" href="{{ route('vehicle-documents.create', request()->only('vehicle_id')) }}">+ Upload document</a>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('vehicle-documents.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Type, number, file or vehicle">
        <select class="input" name="vehicle_id">
            <option value="">All vehicles</option>
            @foreach($vehicles as $vehicle)
                <option value="{{ $vehicle->id }}" @selected((string) $vehicle_id === (string) $vehicle->id)>{{ $vehicle->registration_number }}</option>
            @endforeach
        </select>
        <select class="input" name="document_type">
            <option value="">All types</option>
            @foreach(App\Models\VehicleDocument::TYPES as $key => $label)
                <option value="{{ $key }}" @selected($document_type === $key)>{{ $label }}</option>
            @endforeach
            @if($document_type && ! array_key_exists($document_type, App\Models\VehicleDocument::TYPES))
                <option value="{{ $document_type }}" @selected(true)>{{ $document_type }}</option>
            @endif
        </select>
        <select class="input" name="document_status">
            <option value="">Any validity</option>
            @foreach([App\Models\VehicleDocument::STATUS_ACTIVE => 'Active', App\Models\VehicleDocument::STATUS_EXPIRING => 'Expiring soon', App\Models\VehicleDocument::STATUS_EXPIRED => 'Expired'] as $value => $label)
                <option value="{{ $value }}" @selected($document_status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $vehicle_id || $document_type || $document_status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('vehicle-documents.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Vehicle</th>
                    <th>Type</th>
                    <th>Document</th>
                    <th>Validity</th>
                    <th>Status</th>
                    <th>File</th>
                    <th>Uploaded</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($documents as $doc)
                @php($status = $doc->documentStatus())
                <tr class="border-b">
                    <td class="py-3 font-medium">{{ $doc->vehicle?->registration_number ?? '—' }}</td>
                    <td>{{ $doc->typeLabel() }}</td>
                    <td class="text-xs">{{ $doc->document_number ?? '—' }}</td>
                    <td class="text-xs">
                        {{ $doc->issue_date?->format('d M Y') ?? '—' }} → {{ $doc->expiry_date?->format('d M Y') ?? '—' }}
                    </td>
                    <td>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($status === App\Models\VehicleDocument::STATUS_ACTIVE) bg-emerald-100 text-emerald-700
                            @elseif($status === App\Models\VehicleDocument::STATUS_EXPIRING) bg-amber-100 text-amber-700
                            @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($status) }}</span>
                    </td>
                    <td class="text-xs">
                        {{ $doc->original_filename }}
                        <span class="block text-slate-500">{{ $doc->sizeInKb() }} KB</span>
                    </td>
                    <td class="text-xs">
                        {{ $doc->created_at?->format('Y-m-d H:i') }}
                        @if($doc->uploadedBy)<span class="block text-slate-500">by {{ $doc->uploadedBy->name }}</span>@endif
                    </td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('download', $doc)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('vehicle-documents.download', $doc->id) }}">Download</a>
                            @endcan
                            @can('update', $doc)
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('vehicle-documents.edit', $doc->id) }}">Edit</a>
                            @endcan
                            @can('delete', $doc)
                                <form method="POST" action="{{ route('vehicle-documents.destroy', $doc->id) }}"
                                      onsubmit="return confirm(@js('Delete this '.$doc->typeLabel().' document for '.$doc->vehicle?->registration_number.'? The record is soft-deleted, the file is retained and the action is audited.'))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No vehicle documents found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $documents->firstItem() ?? 0 }}–{{ $documents->lastItem() ?? 0 }} of {{ $documents->total() }} documents.</p>
        {{ $documents->links() }}
    </div>
</div>
@endsection
