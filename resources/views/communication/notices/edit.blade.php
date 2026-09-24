@extends('layouts.app')

@section('title', 'Edit Notice')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Notice</h2>
            <p class="panel-subtitle">{{ $notice->title }} · currently {{ $notice->status }}{{ $notice->isPublished() ? ' — changes are visible immediately' : '' }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.show', $notice) }}">Back</a>
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

    <form method="POST" action="{{ route('notices.update', $notice) }}" enctype="multipart/form-data" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('communication.notices._form')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Update notice</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.show', $notice) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
