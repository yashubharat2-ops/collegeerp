@extends('layouts.app') @section('title','Academic Calendar') @section('content')
<div class="flex justify-between mb-5">
    <p class="text-slate-500">Events are extensible and scoped to the active college.</p>
    <a class="btn-primary" href="{{route('academic-calendar.create')}}">Add event</a>
</div>

{{-- Bulk selection for the calendar. Export only: creating, editing and removing
     events keeps its existing single-record, permission-checked path. --}}
<x-list.bulk-selection-bar module="academic_calendar">
    @if(auth()->user()?->hasPermission('academic_calendar.view'))
        <button type="button" data-bulk-action="export"
                class="button !py-2 !text-xs font-semibold">
            Export selected
        </button>
    @endif
</x-list.bulk-selection-bar>

<div class="card overflow-x-auto">
    <table class="table">
        <tr>
            <th class="w-10"><x-list.select-all /></th>
            <th>Event</th><th>Type</th><th>Dates</th><th>Academic year</th><th>Status</th>
        </tr>
        @forelse($items as $i)
            <tr>
                <td><x-list.row-checkbox :id="$i->id" /></td>
                <td>{{$i->title}}</td>
                <td>{{$i->event_type}}</td>
                <td>{{$i->start_date->format('d M Y')}} – {{$i->end_date->format('d M Y')}}</td>
                <td>{{$i->academicYear?->name}}</td>
                <td>{{$i->status}}</td>
            </tr>
        @empty
            <tr><td colspan="6">No calendar events.</td></tr>
        @endforelse
    </table>
    {{$items->links()}}
</div>
@endsection
