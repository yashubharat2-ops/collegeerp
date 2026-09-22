@extends('layouts.app')

@section('title', 'Edit Issue Remarks')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit remarks</h2>
            <p class="panel-subtitle">{{ $transaction->bookCopy?->label() ?? 'Copy' }} · {{ $transaction->libraryMember?->label() ?? 'Member' }}. Dates, the copy and the member are not edited here.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.show', $transaction) }}">Back</a>
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

    <form method="POST" action="{{ route('library-transactions.update', $transaction) }}" class="mt-6 grid gap-4">
        @csrf
        @method('PUT')
        <div>
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $transaction->remarks) }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="flex gap-2">
            <button class="button" type="submit">Save remarks</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.show', $transaction) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
