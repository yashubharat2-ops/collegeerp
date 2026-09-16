@extends('layouts.app')
@section('title','Merit List Detail')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">{{ $meritList->name }} ({{ $meritList->code }})</h2>
                <p class="panel-subtitle">Year: {{ $meritList->academicYear?->name ?? '—' }} | Program: {{ $meritList->program?->name ?? '—' }} | Status: {{ ucfirst($meritList->status) }} | Published: {{ $meritList->is_published ? 'Yes' : 'No' }}</p>
                @if($meritList->description)<p class="mt-2 text-sm">{{ $meritList->description }}</p>@endif
                @if($meritList->remarks)<p class="mt-1 text-xs text-slate-500">{{ $meritList->remarks }}</p>@endif
            </div>
            <div class="flex gap-2">
                @can('update', $meritList)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-merit-lists.edit', $meritList) }}">Edit list</a>
                @endcan
                @can('create', App\Models\AdmissionMeritEntry::class)
                    <a class="button" href="{{ route('admission-merit-entries.create', ['merit_list_id' => $meritList->id]) }}">+ Add entry</a>
                @endcan
            </div>
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Merit Entries</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Rank</th><th>Score</th><th>Application</th><th>Applicant</th><th>Status</th><th>Remarks</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($entries as $entry)
                    <tr class="border-b">
                        <td class="py-2">{{ $entry->rank ?? '—' }}</td>
                        <td>{{ $entry->merit_score ?? '—' }}</td>
                        <td class="font-medium">{{ $entry->application->application_number ?? '—' }}</td>
                        <td>{{ $entry->applicant->first_name ?? '' }} {{ $entry->applicant->last_name ?? '' }}</td>
                        <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($entry->selection_status === 'selected') bg-emerald-100 text-emerald-700
                            @elseif($entry->selection_status === 'waitlisted') bg-amber-100 text-amber-700
                            @elseif($entry->selection_status === 'rejected') bg-rose-100 text-rose-700
                            @else bg-slate-200 text-slate-700 @endif
                        ">{{ ucfirst($entry->selection_status) }}</span></td>
                        <td class="text-xs">{{ $entry->remarks ?? '—' }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                @can('update', $entry)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-merit-entries.edit', $entry) }}">Edit</a>
                                @endcan
                                @can('delete', $entry)
                                    <form method="POST" action="{{ route('admission-merit-entries.destroy', $entry) }}" onsubmit="return confirm('Delete entry?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-slate-500">No entries yet. Add applications with merit score and rank.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    </div>
</div>
@endsection
