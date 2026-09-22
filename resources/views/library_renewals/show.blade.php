@extends('layouts.app')

@section('title', 'Renewal')

@section('content')
<div class="panel max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Renewal</h2>
            <p class="panel-subtitle">{{ $renewal->issueTransaction?->bookCopy?->label() ?? 'Copy' }} · renewed {{ $renewal->renewed_on?->format('d M Y') }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-renewals.index') }}">Back</a>
    </div>

    <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-slate-500">Issue</dt>
            <dd>
                @can('view', $renewal->issueTransaction)
                    <a class="text-indigo-700 hover:underline" href="{{ route('library-transactions.show', $renewal->issueTransaction) }}">View issue</a>
                @else
                    Issue #{{ $renewal->issue_transaction_id }}
                @endcan
            </dd>
        </div>
        <div>
            <dt class="text-slate-500">Member</dt>
            <dd>{{ $renewal->issueTransaction?->libraryMember?->label() ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Original issue date</dt>
            <dd>{{ $renewal->issueTransaction?->issued_on?->format('d M Y') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Renewed on</dt>
            <dd>{{ $renewal->renewed_on?->format('d M Y') }} @if($renewal->renewer) by {{ $renewal->renewer->name }} @endif</dd>
        </div>
        <div>
            <dt class="text-slate-500">Previous due date</dt>
            <dd>{{ $renewal->old_due_date?->format('d M Y') }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">New due date</dt>
            <dd>{{ $renewal->new_due_date?->format('d M Y') }}</dd>
        </div>
    </dl>

    <div class="mt-6">
        <h3 class="font-semibold">Remarks</h3>
        <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $renewal->remarks ?: 'No remarks.' }}</p>
    </div>

    <p class="mt-6 text-xs text-slate-500">Renewals are kept as history and cannot be edited or deleted.</p>
</div>
@endsection
