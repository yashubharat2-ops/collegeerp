@extends('layouts.app')

@section('title', 'Edit Book Copy')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Book Copy</h2>
            <p class="panel-subtitle"><span class="font-mono">{{ $copy->accession_number }}</span> · {{ $copy->book?->title ?? 'Book' }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.show', $copy) }}">Back</a>
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

    <form method="POST" action="{{ route('book-copies.update', $copy) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('book_copies._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update copy</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.show', $copy) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
