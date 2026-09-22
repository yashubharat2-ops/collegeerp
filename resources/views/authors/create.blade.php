@extends('layouts.app')

@section('title', 'Add Author')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Author</h2>
            <p class="panel-subtitle">Record an author once and credit them on any number of books. Names are unique within the college (case and spacing ignored).</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('authors.index') }}">Back</a>
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

    <form method="POST" action="{{ route('authors.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('authors._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save author</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('authors.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
