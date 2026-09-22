@extends('layouts.app')

@section('title', 'Edit Book')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Book</h2>
            <p class="panel-subtitle">{{ $book->title }} ({{ $book->code }})</p>
        </div>
        <div class="flex gap-2">
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.show', $book) }}">View</a>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.index') }}">Back</a>
        </div>
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

    <form method="POST" action="{{ route('books.update', $book) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('books._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update book</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.show', $book) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
