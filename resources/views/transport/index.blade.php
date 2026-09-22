@extends('layouts.app')
@section('title', $title)
@section('content')
@php($params = $parent ? ['transport_route' => $parent->id] : [])
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">{{ $title }}{{ $parent ? ' — '.$parent->name : '' }}</h2>
            <p class="panel-subtitle">Transport masters for the active college.</p>
        </div>
        <div class="flex gap-2">
            @if($parent)<a class="button" href="{{ route('transport-routes.index') }}">Back to routes</a>@endif
            @can('create', $model)<a class="button" href="{{ route($routeName.'.create', $params) }}">Add record</a>@endcan
        </div>
    </div>
    @if($errors->any())<div class="alert-error mt-4">{{ $errors->first() }}</div>@endif
    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
        <label>Search<input class="input" name="search" value="{{ request('search') }}" placeholder="Name or identifier"></label>
        <label>Status<select class="input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <button class="button">Filter</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route($routeName.'.index', $params) }}">Reset</a>
    </form>
    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-slate-500"><tr>
                @foreach($fields as $field => $type)
                    @if(!in_array($field, ['remarks', 'description', 'landmark']))<th class="whitespace-nowrap px-3 py-3">{{ $field === 'faculty_id' ? 'Staff' : \Illuminate\Support\Str::headline($field) }}</th>@endif
                @endforeach
                <th class="px-3 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse($records as $record)
                    <tr>
                        @foreach($fields as $field => $type)
                            @if(!in_array($field, ['remarks', 'description', 'landmark']))
                                <td class="whitespace-nowrap px-3 py-3">{{ $field === 'faculty_id' ? ($record->faculty?->full_name ?? 'Archived staff') : ($record->$field ?? '—') }}</td>
                            @endif
                        @endforeach
                        <td class="px-3 py-3"><div class="flex gap-2">
                            @if($record instanceof \App\Models\TransportRoute)
                                @can('view', $record)<a class="button" href="{{ route('transport-stops.index', ['transport_route' => $record->id]) }}">Stops</a>@endcan
                            @endif
                            @can('update', $record)<a class="button" href="{{ route($routeName.'.edit', $params + ['record' => $record->id]) }}">Edit</a>@endcan
                            @can('delete', $record)
                                <form method="POST" action="{{ route($routeName.'.destroy', $params + ['record' => $record->id]) }}" onsubmit="return confirm('Archive this record?')">@csrf @method('DELETE')<button class="button !bg-red-600">Delete</button></form>
                            @endcan
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($fields) + 1 }}" class="px-3 py-8 text-center text-slate-500">No records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $records->links() }}</div>
</div>
@endsection
