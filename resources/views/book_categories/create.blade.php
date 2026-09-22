@extends('layouts.app')

@section('title', 'Add Book Category')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Book Category</h2>
            <p class="panel-subtitle">Create a classification for this college's catalogue. The code must be unique among the college's active categories.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-categories.index') }}">Back</a>
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

    <form method="POST" action="{{ route('book-categories.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('book_categories._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save book category</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-categories.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
