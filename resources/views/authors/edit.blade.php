@extends('layouts.app')

@section('title', 'Edit Author')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Author</h2>
            <p class="panel-subtitle">{{ $author->name }} · credited on {{ $author->books_count }} {{ Str::plural('book', $author->books_count) }}</p>
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

    <form method="POST" action="{{ route('authors.update', $author) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('authors._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update author</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('authors.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
