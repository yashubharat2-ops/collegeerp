@extends('layouts.app')

@section('title', 'New Notice')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">New Notice / Announcement</h2>
            <p class="panel-subtitle">The notice is saved as a draft for the active college. Publish it from its detail page when it is ready.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.index') }}">Back</a>
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

    <form method="POST" action="{{ route('notices.store') }}" enctype="multipart/form-data" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('communication.notices._form')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Save draft</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
