@extends('layouts.app')

@section('title', 'Renewals')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Renewals</h2>
            <p class="panel-subtitle">Due-date extensions for the active college. Each row is kept; renewing does not overwrite the original issue.</p>
        </div>
        @can('create', App\Models\LibraryRenewal::class)
            <a class="button" href="{{ route('library-renewals.create') }}">+ Renew an issue</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3" method="GET" action="{{ route('library-renewals.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Accession, title or member code">
        </div>
        <div class="flex items-end gap-2">
            @if($filters['issue_transaction_id'])
                <input type="hidden" name="issue_transaction_id" value="{{ $filters['issue_transaction_id'] }}">
            @endif
            <button class="button" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Copy</th>
                    <th>Member</th>
                    <th>Previous due</th>
                    <th>New due</th>
                    <th>Renewed</th>
                    <th>By</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($renewals as $renewal)
                    <tr class="border-b">
                        <td class="py-2">
                            <a class="font-medium text-indigo-700 hover:underline" href="{{ route('library-renewals.show', $renewal) }}">{{ $renewal->issueTransaction?->bookCopy?->accession_number ?? 'Copy' }}</a>
                            <div class="max-w-xs truncate text-xs text-slate-500">{{ $renewal->issueTransaction?->bookCopy?->book?->title ?? '—' }}</div>
                        </td>
                        <td>
                            <div>{{ $renewal->issueTransaction?->libraryMember?->studentName() ?? '—' }}</div>
                            <div class="font-mono text-xs text-slate-500">{{ $renewal->issueTransaction?->libraryMember?->member_code ?? '—' }}</div>
                        </td>
                        <td>{{ $renewal->old_due_date?->format('d M Y') }}</td>
                        <td>{{ $renewal->new_due_date?->format('d M Y') }}</td>
                        <td>{{ $renewal->renewed_on?->format('d M Y') }}</td>
                        <td>{{ $renewal->renewer?->name ?? '—' }}</td>
                        <td class="py-2 text-right">
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-renewals.show', $renewal) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No renewals recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $renewals->links() }}</div>
</div>
@endsection
