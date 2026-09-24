@extends('layouts.app')

@section('title', 'Circulars')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Circulars</h2>
            <p class="panel-subtitle">Formal, numbered circulars of the active college. A circular number is unique within the college and is never reused, even after the circular is deleted.</p>
        </div>
        @can('create', App\Models\Circular::class)
            <a class="button" href="{{ route('circulars.create') }}">+ New circular</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" method="GET" action="{{ route('circulars.index') }}">
        <div class="sm:col-span-2">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Number, title or subject" maxlength="100">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="target_type">Audience</label>
            <select class="input" id="target_type" name="target_type">
                <option value="">All audiences</option>
                @foreach($targets as $targetValue => $targetLabel)
                    <option value="{{ $targetValue }}" @selected($filters['target_type'] === $targetValue)>{{ $targetLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="date_from">Issued from</label>
            <input class="input" id="date_from" name="date_from" type="date" value="{{ $filters['date_from']?->format('Y-m-d') }}">
        </div>
        <div>
            <label class="label" for="date_to">Issued to</label>
            <input class="input" id="date_to" name="date_to" type="date" value="{{ $filters['date_to']?->format('Y-m-d') }}">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Number</th>
                    <th>Title / subject</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th>Issue date</th>
                    <th>Expires</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($circulars as $circular)
                    <tr class="border-b align-top">
                        <td class="whitespace-nowrap py-2 pr-3"><span class="font-mono text-xs">{{ $circular->circular_number }}</span></td>
                        <td class="max-w-sm py-2 pr-3">
                            <a class="font-medium text-indigo-600 hover:underline" href="{{ route('circulars.show', $circular) }}">{{ $circular->title }}</a>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $circular->subject }}</p>
                            @if($circular->hasAttachment())
                                <p class="mt-0.5 text-xs text-slate-500">📎 attachment</p>
                            @endif
                        </td>
                        <td class="py-2 pr-3">{{ $circular->targetLabel() }}</td>
                        <td class="py-2 pr-3">
                            <div class="flex flex-wrap gap-1">
                                @include('communication.partials.badge', ['kind' => 'status', 'value' => $circular->status])
                                @if($circular->isPublished())
                                    @include('communication.partials.badge', ['kind' => 'visibility', 'value' => $circular->visibility()])
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $circular->issue_date?->format('d M Y') }}</td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $circular->expires_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="py-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.show', $circular) }}">View</a>
                                @can('update', $circular)
                                    @unless($circular->isArchived())
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.edit', $circular) }}">Edit</a>
                                    @endunless
                                @endcan
                                @can('delete', $circular)
                                    <form method="POST" action="{{ route('circulars.destroy', $circular) }}" onsubmit="return confirm(@js('Delete circular '.$circular->circular_number.'? It will be archived (soft-deleted); the number stays reserved.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-slate-500" colspan="7">
                            @if(array_filter($filters, fn ($value) => filled($value)))
                                No circulars match these filters.
                            @else
                                No circulars have been issued for this college yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $circulars->links() }}</div>
</div>
@endsection
