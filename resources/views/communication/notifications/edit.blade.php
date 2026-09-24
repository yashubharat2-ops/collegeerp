@extends('layouts.app')

@section('title', 'Edit Notification')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Notification</h2>
            <p class="panel-subtitle">The recipient of a sent notification cannot be changed; send a new notification instead.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.show', $notification) }}">Back</a>
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

    <form method="POST" action="{{ route('notifications.update', $notification) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        <div class="sm:col-span-2">
            <span class="label">Recipient</span>
            <p class="rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm">{{ $notification->recipientTypeLabel() }}: {{ $recipientLabel }}</p>
        </div>
        @include('communication.notifications._content_fields')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Update notification</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.show', $notification) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
