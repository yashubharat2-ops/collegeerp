@extends('layouts.app') @section('title','Faculty Workload') @section('content')
<p class="mb-5 text-slate-500">Derived from active timetable entries; no duplicate workload facts are stored.</p>

{{-- Bulk selection over the derived workload lines. A line has no primary key of
     its own, so its checkbox carries the id of the group's representative active
     timetable entry (rep_id — the lowest id of the group, derived by
     FacultyWorkloadService, the same service that builds this listing). The
     handler re-queries that id inside the active college, and the CSV endpoint
     re-derives the group from it server-side: a hand-edited id cannot change
     what a line contains, and only active timetable entries count as workload. --}}
<x-list.bulk-selection-bar module="academic_workload">
    @if(auth()->user()?->hasPermission('academic_workload.view'))
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
            <th>Faculty</th><th>Subject</th><th>Section</th><th>Academic term</th><th>Periods</th><th>Weekly hours</th>
        </tr>
        @forelse($items as $i)
            <tr>
                <td><x-list.row-checkbox :id="$i->rep_id" /></td>
                <td>{{$i->faculty?->full_name}}</td>
                <td>{{$i->subject?->name}}</td>
                <td>{{$i->section?->name}}</td>
                <td>{{$i->academicTerm?->name}}</td>
                <td>{{$i->periods}}</td>
                <td>{{number_format((float)$i->weekly_hours,2)}}</td>
            </tr>
        @empty
            <tr><td colspan="7">No workload can be derived until timetable entries exist.</td></tr>
        @endforelse
    </table>
    {{$items->links()}}
</div>
@endsection
