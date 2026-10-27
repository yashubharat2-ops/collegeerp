@extends('layouts.app')

@section('title', 'Timetable')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <p class="text-slate-500">Conflicts are checked for faculty, section and room.</p>
    @if (auth()->user()?->hasPermission('academic_timetables.create'))
        <a class="btn-primary" href="{{ route('academic-timetables.create') }}">Add timetable entry</a>
    @endif
</div>

<div data-bulk-scope>
    <x-list.bulk-selection-bar module="academic_timetables">
        @if(auth()->user()?->hasPermission('academic_timetables.view'))
            <button type="button" data-bulk-action="export" class="button !py-2 !text-xs font-semibold">Export selected</button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="card overflow-x-auto">
        <table class="table">
            <tr>
                <th class="w-10"><x-list.select-all /></th>
                <th>Day</th>
                <th>Time</th>
                <th>Section</th>
                <th>Subject</th>
                <th>Faculty</th>
                <th>Room</th>
                <th class="text-right">Actions</th>
            </tr>
            @forelse($items as $i)
                <tr>
                    <td><x-list.row-checkbox :id="$i->id" /></td>
                    <td>{{ ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$i->day_of_week] ?? $i->day_of_week }}</td>
                    <td>{{ $i->start_time }}–{{ $i->end_time }}</td>
                    <td>{{ $i->section?->name }}</td>
                    <td>{{ $i->subject?->name }}</td>
                    <td>{{ $i->faculty?->full_name }}</td>
                    <td>{{ $i->room ?: '—' }}</td>
                    <td>
                        {{-- The list used to render no actions at all, which left
                             academic-timetables.update/destroy registered but
                             unreachable: a mistyped slot could never be corrected
                             or removed. --}}
                        <div class="flex items-center justify-end gap-2">
                            @if(auth()->user()?->hasPermission('academic_timetables.update'))
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('academic-timetables.edit', $i) }}">Edit</a>
                            @endif
                            @if(auth()->user()?->hasPermission('academic_timetables.delete'))
                                <form method="POST" action="{{ route('academic-timetables.destroy', $i) }}"
                                      onsubmit="return confirm(@js('Delete this timetable entry? The slot becomes free for another entry.'))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-primary !bg-rose-600 hover:!bg-rose-700 !px-2 !py-1 !text-xs" type="submit">Delete</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">No timetable entries.</td></tr>
            @endforelse
        </table>
        {{ $items->links() }}
    </div>
</div>
@endsection
