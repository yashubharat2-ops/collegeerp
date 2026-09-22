@extends('layouts.app')

@section('title', 'Issue Details')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">{{ $transaction->bookCopy?->accession_number ?? 'Issue' }}</h2>
                <p class="panel-subtitle">
                    {{ $transaction->bookCopy?->book?->title ?? 'Book' }}
                    · {{ $transaction->libraryMember?->studentName() ?? 'Member' }}
                    · @include('library.status', ['status' => $transaction->status])
                    @if($transaction->isOverdue())
                        <span class="ml-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">Overdue</span>
                    @endif
                </p>
            </div>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.index') }}">Back</a>
        </div>

        <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Copy</dt>
                <dd>
                    @can('view', $transaction->bookCopy)
                        <a class="text-indigo-700 hover:underline" href="{{ route('book-copies.show', $transaction->bookCopy) }}">{{ $transaction->bookCopy?->label() }}</a>
                    @else
                        {{ $transaction->bookCopy?->label() ?? '—' }}
                    @endcan
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Member</dt>
                <dd>
                    @can('view', $transaction->libraryMember)
                        <a class="text-indigo-700 hover:underline" href="{{ route('library-members.show', $transaction->libraryMember) }}">{{ $transaction->libraryMember?->label() }}</a>
                    @else
                        {{ $transaction->libraryMember?->label() ?? '—' }}
                    @endcan
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Issued on</dt>
                <dd>{{ $transaction->issued_on?->format('d M Y') }} @if($transaction->issuer) by {{ $transaction->issuer->name }} @endif</dd>
            </div>
            <div>
                <dt class="text-slate-500">Current due date</dt>
                <dd>{{ $transaction->due_on?->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Returned on</dt>
                <dd>{{ $transaction->returned_on?->format('d M Y') ?? '—' }} @if($transaction->returner) by {{ $transaction->returner->name }} @endif</dd>
            </div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd>@include('library.status', ['status' => $transaction->status])</dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="font-semibold">Remarks</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $transaction->remarks ?: 'No remarks.' }}</p>
        </div>

        <div class="mt-6 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
            @can('update', $transaction)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.edit', $transaction) }}">Edit remarks</a>
            @endcan
            @if($transaction->isIssued())
                @can('create', App\Models\LibraryRenewal::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-renewals.create', ['issue_transaction_id' => $transaction->id]) }}">Renew</a>
                @endcan
            @endif
        </div>
    </div>

    @if($transaction->isIssued())
        <div class="grid gap-6 lg:grid-cols-2">
            @can('returnCopy', $transaction)
                <div class="panel">
                    <h3 class="font-semibold">Return copy</h3>
                    <p class="panel-subtitle">Returning closes this issue and makes the copy available again. The issue record is kept.</p>
                    <form method="POST" action="{{ route('library-transactions.return', $transaction) }}" class="mt-4 grid gap-3">
                        @csrf
                        <div>
                            <label class="label" for="returned_on">Return date</label>
                            <input class="input" id="returned_on" name="returned_on" type="date" required value="{{ old('returned_on', now()->toDateString()) }}">
                            <p class="mt-1 text-xs text-rose-600">@error('returned_on'){{ $message }}@enderror</p>
                        </div>
                        <div>
                            <label class="label" for="return_remarks">Return note</label>
                            <textarea class="input" id="return_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                        </div>
                        <button class="button" type="submit">Record return</button>
                    </form>
                </div>
            @endcan
            @can('markLost', $transaction)
                <div class="panel">
                    <h3 class="font-semibold">Mark lost</h3>
                    <p class="panel-subtitle">Closes the issue and marks the copy lost. The copy is not made available. This cannot be undone from this screen.</p>
                    <form method="POST" action="{{ route('library-transactions.lost', $transaction) }}" class="mt-4 grid gap-3" onsubmit="return confirm('Mark this issue as lost? The copy will no longer be available.');">
                        @csrf
                        <div>
                            <label class="label" for="lost_remarks">Note</label>
                            <textarea class="input" id="lost_remarks" name="remarks" rows="2" maxlength="2000"></textarea>
                        </div>
                        <button class="button !bg-rose-600 hover:!bg-rose-700" type="submit">Mark lost</button>
                    </form>
                </div>
            @endcan
        </div>
    @endif

    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">Renewal history</h3>
                <p class="panel-subtitle">Each renewal is stored separately. The original issue date is never overwritten.</p>
            </div>
            @can('viewAny', App\Models\LibraryRenewal::class)
                <a class="text-sm text-indigo-600 hover:underline" href="{{ route('library-renewals.index', ['issue_transaction_id' => $transaction->id]) }}">All renewals</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Renewed</th>
                        <th>Previous due</th>
                        <th>New due</th>
                        <th>By</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transaction->renewals as $renewal)
                        <tr class="border-b">
                            <td class="py-2">
                                @can('view', $renewal)
                                    <a class="text-indigo-700 hover:underline" href="{{ route('library-renewals.show', $renewal) }}">{{ $renewal->renewed_on?->format('d M Y') }}</a>
                                @else
                                    {{ $renewal->renewed_on?->format('d M Y') }}
                                @endcan
                            </td>
                            <td>{{ $renewal->old_due_date?->format('d M Y') }}</td>
                            <td>{{ $renewal->new_due_date?->format('d M Y') }}</td>
                            <td>{{ $renewal->renewer?->name ?? '—' }}</td>
                            <td class="max-w-xs truncate">{{ $renewal->remarks ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="5">This issue has not been renewed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
