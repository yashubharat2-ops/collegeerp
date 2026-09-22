@extends('layouts.app')

@section('title', 'Add Library Member')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Library Member</h2>
            <p class="panel-subtitle">Open a library membership for an existing student enrollment of this college. An enrollment can have only one active membership.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.index') }}">Back</a>
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

    <form method="POST" action="{{ route('library-members.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('library_members._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save member</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
