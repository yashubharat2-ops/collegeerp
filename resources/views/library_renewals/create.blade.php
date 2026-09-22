@extends('layouts.app')

@section('title', 'Renew an Issue')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Renew an Issue</h2>
            <p class="panel-subtitle">Extend the due date of an active issue. The original issue date is preserved, and this renewal is stored as its own record.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-renewals.index') }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($issues->isEmpty())
        <div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">There is no active issue to renew.</div>
    @endif

    <form method="POST" action="{{ route('library-renewals.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        <div class="sm:col-span-2">
            <label class="label" for="issue_transaction_id">Active issue</label>
            <select class="input" id="issue_transaction_id" name="issue_transaction_id" required @disabled($issues->isEmpty())>
                <option value="">Select an issue</option>
                @foreach($issues as $issue)
                    <option value="{{ $issue->id }}" data-due="{{ $issue->due_on?->format('Y-m-d') }}" @selected((int) old('issue_transaction_id', $selectedIssueId ?? 0) === $issue->id)>
                        {{ $issue->bookCopy?->accession_number ?? 'Copy' }} · {{ $issue->bookCopy?->book?->title ?? 'Book' }}
                        · {{ $issue->libraryMember?->member_code ?? 'Member' }}
                        · due {{ $issue->due_on?->format('d M Y') }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500" id="current-due"></p>
            <p class="mt-1 text-xs text-rose-600">@error('issue_transaction_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="label" for="renewed_on">Renewed on</label>
            <input class="input" id="renewed_on" name="renewed_on" type="date" required value="{{ old('renewed_on', now()->toDateString()) }}">
            <p class="mt-1 text-xs text-rose-600">@error('renewed_on'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="label" for="new_due_date">New due date</label>
            <input class="input" id="new_due_date" name="new_due_date" type="date" required value="{{ old('new_due_date') }}">
            <p class="mt-1 text-xs text-slate-500">Must be later than the issue's current due date.</p>
            <p class="mt-1 text-xs text-rose-600">@error('new_due_date'){{ $message }}@enderror</p>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit" @disabled($issues->isEmpty())>Renew issue</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-renewals.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    const select = document.getElementById('issue_transaction_id');
    const hint = document.getElementById('current-due');
    const show = () => {
        const due = select?.selectedOptions[0]?.dataset.due;
        if (hint) hint.textContent = due ? 'Current due date: ' + due : '';
    };
    select?.addEventListener('change', show);
    show();
</script>
@endpush
