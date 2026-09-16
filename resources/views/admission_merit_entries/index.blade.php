@extends('layouts.app')
@section('title','Merit Entries')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Merit Entries</h2>
            <p class="panel-subtitle">All merit entries across lists. Use merit list detail for focused management.</p>
        </div>
        @can('create', App\Models\AdmissionMeritEntry::class)
            <a class="button" href="{{ route('admission-merit-entries.create') }}">+ New entry</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-merit-entries.index') }}" class="mt-6 grid gap-3 sm:grid-cols-3">
        <select class="input" name="merit_list_id">
            <option value="">All merit lists</option>
            @foreach($meritLists as $list)
                <option value="{{ $list->id }}" @selected((string)$merit_list_id === (string)$list->id)>{{ $list->code }} — {{ $list->name }}</option>
            @endforeach
        </select>
        <select class="input" name="selection_status">
            <option value="">All selection statuses</option>
            <option value="pending" @selected($selection_status === 'pending')>Pending</option>
            <option value="selected" @selected($selection_status === 'selected')>Selected</option>
            <option value="waitlisted" @selected($selection_status === 'waitlisted')>Waitlisted</option>
            <option value="rejected" @selected($selection_status === 'rejected')>Rejected</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($merit_list_id || $selection_status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-merit-entries.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3">Merit List</th><th>Rank</th><th>Score</th><th>Application</th><th>Applicant</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($entries as $entry)
                <tr class="border-b">
                    <td class="py-3">{{ $entry->meritList->code ?? '—' }}</td>
                    <td>{{ $entry->rank ?? '—' }}</td>
                    <td>{{ $entry->merit_score ?? '—' }}</td>
                    <td>{{ $entry->application->application_number ?? '—' }}</td>
                    <td>{{ $entry->applicant->first_name }} {{ $entry->applicant->last_name }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-200">{{ ucfirst($entry->selection_status) }}</span></td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            @can('update', $entry)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-merit-entries.edit', $entry) }}">Edit</a>
                            @endcan
                            @can('delete', $entry)
                                <form method="POST" action="{{ route('admission-merit-entries.destroy', $entry) }}" onsubmit="return confirm('Delete?')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-slate-500">No entries found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">Showing {{ $entries->firstItem() ?? 0 }}–{{ $entries->lastItem() ?? 0 }} of {{ $entries->total() }} entries.</p>
        {{ $entries->links() }}
    </div>
</div>
@endsection
