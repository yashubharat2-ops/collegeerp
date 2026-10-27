@extends('layouts.app')

@section('title', 'Academic Calendar')

@section('content')
{{-- Original intro row and card shell are part of the bulk-action layout
     contract (AcademicBulkActionTest): the new Term column and the Edit/Delete
     actions were added INSIDE the existing table, not around it. --}}
<div class="flex justify-between mb-5">
    <p class="text-slate-500">Events are extensible and scoped to the active college.</p>
    @if (auth()->user()?->hasPermission('academic_calendar.create'))
        <a class="btn-primary" href="{{ route('academic-calendar.create') }}">Add event</a>
    @endif
</div>

<div data-bulk-scope>
    <x-list.bulk-selection-bar module="academic_calendar">
        @if(auth()->user()?->hasPermission('academic_calendar.view'))
            <button type="button" data-bulk-action="export" class="button !py-2 !text-xs font-semibold">Export selected</button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="card">
        <table class="table">
            <tr>
                <th class="w-10"><x-list.select-all /></th>
                <th>Event</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Academic year</th>
                <th>Term</th>
                <th>Status</th>
                <th class="text-right">Actions</th>
            </tr>
            @forelse($items as $i)
                <tr>
                    <td><x-list.row-checkbox :id="$i->id" /></td>
                    <td>{{ $i->title }}</td>
                    <td>{{ $i->event_type }}</td>
                    <td>{{ $i->start_date->format('d M Y') }} – {{ $i->end_date->format('d M Y') }}</td>
                    <td>{{ $i->academicYear?->name }}</td>
                    <td>{{ $i->academicTerm?->name ?? 'Year-wide' }}</td>
                    <td>{{ $i->status }}</td>
                    <td>
                        {{-- Same gap as the timetable: update/destroy existed as
                             endpoints with no control on screen. The "Term" column
                             above is also new — a year-wide event and a term event
                             used to look identical. --}}
                        <div class="flex items-center justify-end gap-2">
                            @if(auth()->user()?->hasPermission('academic_calendar.update'))
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('academic-calendar.edit', $i) }}">Edit</a>
                            @endif
                            @if(auth()->user()?->hasPermission('academic_calendar.delete'))
                                <form method="POST" action="{{ route('academic-calendar.destroy', $i) }}"
                                      onsubmit="return confirm(@js('Delete calendar event '.$i->title.'?'))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-primary !bg-rose-600 hover:!bg-rose-700 !px-2 !py-1 !text-xs" type="submit">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">No calendar events.</td></tr>
            @endforelse
        </table>
        {{ $items->links() }}
    </div>
</div>
@endsection
