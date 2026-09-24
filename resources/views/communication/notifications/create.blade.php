@extends('layouts.app')

@section('title', 'Send Notification')

@section('content')
@php
    $selectedRecipient = old('recipient', old('recipient_type') && old('recipient_id') ? old('recipient_type').':'.old('recipient_id') : '');
    $recipientLimit = (int) config('communication.recipient_option_limit', 500);
@endphp
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Send Notification</h2>
            <p class="panel-subtitle">Send an internal, in-app notification to one existing user, student or staff member of the active college. Nothing is delivered outside the ERP.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.index') }}">Back</a>
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

    <form method="POST" action="{{ route('notifications.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        <div class="sm:col-span-2">
            <label class="label" for="recipient">Recipient</label>
            <select class="input" id="recipient" name="recipient" required>
                <option value="">— Choose a recipient —</option>
                @foreach($recipientTypes as $typeValue => $typeLabel)
                    <optgroup label="{{ \Illuminate\Support\Str::plural($typeLabel) }}">
                        @forelse($recipientOptions[$typeValue] as $recipientId => $recipientLabel)
                            <option value="{{ $typeValue }}:{{ $recipientId }}" @selected($selectedRecipient === $typeValue.':'.$recipientId)>{{ $recipientLabel }}</option>
                        @empty
                            <option value="" disabled>No {{ strtolower(\Illuminate\Support\Str::plural($typeLabel)) }} in this college</option>
                        @endforelse
                    </optgroup>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Only records of the active college are listed (up to {{ $recipientLimit }} per type, alphabetically).</p>
            <p class="mt-1 text-xs text-rose-600">@error('recipient_type'){{ $message }}@enderror @error('recipient_id'){{ $message }}@enderror</p>
        </div>
        @include('communication.notifications._content_fields')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Send notification</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
