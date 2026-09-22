@extends('layouts.app')

@section('title', 'Issue / Return')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Issue / Return</h2>
            <p class="panel-subtitle">Circulation register for the active college. An issue is kept after return or loss — history is not deleted.</p>
        </div>
        @can('create', App\Models\LibraryTransaction::class)
            <a class="button" href="{{ route('library-transactions.create') }}">+ Issue a copy</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('library-transactions.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Accession, title, member or student">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="overdue" value="1" @checked($overdue)>
                Overdue only
            </label>
            @if($filters['library_member_id'])
                <input type="hidden" name="library_member_id" value="{{ $filters['library_member_id'] }}">
            @endif
            @if($filters['book_copy_id'])
                <input type="hidden" name="book_copy_id" value="{{ $filters['book_copy_id'] }}">
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
                    <th>Issued</th>
                    <th>Due</th>
                    <th>Returned</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $transaction)
                    <tr class="border-b">
                        <td class="py-2">
                            <a class="font-medium text-indigo-700 hover:underline" href="{{ route('library-transactions.show', $transaction) }}">{{ $transaction->bookCopy?->accession_number ?? 'Copy' }}</a>
                            <div class="max-w-xs truncate text-xs text-slate-500">{{ $transaction->bookCopy?->book?->title ?? '—' }}</div>
                        </td>
                        <td>
                            <div>{{ $transaction->libraryMember?->studentName() ?? '—' }}</div>
                            <div class="font-mono text-xs text-slate-500">{{ $transaction->libraryMember?->member_code ?? '—' }}</div>
                        </td>
                        <td>{{ $transaction->issued_on?->format('d M Y') }}</td>
                        <td>
                            {{ $transaction->due_on?->format('d M Y') }}
                            @if($transaction->isOverdue())
                                <div class="text-xs font-semibold text-rose-700">Overdue</div>
                            @endif
                            @if($transaction->renewals_count > 0)
                                <div class="text-xs text-slate-500">Renewed {{ $transaction->renewals_count }}×</div>
                            @endif
                        </td>
                        <td>{{ $transaction->returned_on?->format('d M Y') ?? '—' }}</td>
                        <td>@include('library.status', ['status' => $transaction->status])</td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.show', $transaction) }}">View</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No issues recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $transactions->links() }}</div>
</div>
@endsection
