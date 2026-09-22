@extends('layouts.app')

@section('title', 'Issue a Copy')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Issue a Copy</h2>
            <p class="panel-subtitle">Lend an available copy to an active member of this college. The copy is locked for the duration of the issue so it cannot be lent twice.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.index') }}">Back</a>
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

    @if($copies->isEmpty() || $members->isEmpty())
        <div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
            @if($copies->isEmpty())
                <p>There is no available copy to issue. Add a copy, or return one that is currently out.</p>
            @endif
            @if($members->isEmpty())
                <p class="mt-1">There is no active, unexpired library member. Open a membership first.</p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('library-transactions.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        <div class="sm:col-span-2">
            <label class="label" for="book_copy_id">Available copy</label>
            <select class="input" id="book_copy_id" name="book_copy_id" required @disabled($copies->isEmpty())>
                <option value="">Select a copy</option>
                @foreach($copies as $copy)
                    <option value="{{ $copy->id }}" @selected((int) old('book_copy_id', $selectedCopyId ?? 0) === $copy->id)>{{ $copy->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('book_copy_id'){{ $message }}@enderror</p>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="library_member_id">Library member</label>
            <select class="input" id="library_member_id" name="library_member_id" required @disabled($members->isEmpty())>
                <option value="">Select a member</option>
                @foreach($members as $member)
                    <option value="{{ $member->id }}" @selected((int) old('library_member_id', $selectedMemberId ?? 0) === $member->id)>{{ $member->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('library_member_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="label" for="issued_on">Issue date</label>
            <input class="input" id="issued_on" name="issued_on" type="date" required value="{{ old('issued_on', now()->toDateString()) }}">
            <p class="mt-1 text-xs text-rose-600">@error('issued_on'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="label" for="due_on">Due date</label>
            <input class="input" id="due_on" name="due_on" type="date" required value="{{ old('due_on', now()->addDays(14)->toDateString()) }}">
            <p class="mt-1 text-xs text-slate-500">Must be later than the issue date. 14 days is only a suggestion.</p>
            <p class="mt-1 text-xs text-rose-600">@error('due_on'){{ $message }}@enderror</p>
        </div>
        <div class="sm:col-span-2">
            <label class="label" for="remarks">Remarks</label>
            <textarea class="input" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit" @disabled($copies->isEmpty() || $members->isEmpty())>Issue copy</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
