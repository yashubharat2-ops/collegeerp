@extends('layouts.app')

@section('title', 'Notification')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @include('communication.partials.badge', ['kind' => 'read', 'value' => $notification->isRead() ? 'read' : 'unread'])
                    @include('communication.partials.badge', ['kind' => 'priority', 'value' => $notification->priority])
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $notification->typeLabel() }}</span>
                </div>
                <h2 class="mt-3 break-words text-2xl font-bold tracking-tight">{{ $notification->title }}</h2>
                <p class="panel-subtitle">To {{ $notification->recipientTypeLabel() }}: {{ $recipientLabel }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.index') }}">Back</a>
                @can('markRead', $notification)
                    @if($notification->isRead())
                        <form method="POST" action="{{ route('notifications.unread', $notification) }}">
                            @csrf
                            <input type="hidden" name="return" value="show">
                            <button class="button !bg-slate-200 !text-slate-700" type="submit">Mark unread</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                            @csrf
                            <input type="hidden" name="return" value="show">
                            <button class="button" type="submit">Mark read</button>
                        </form>
                    @endif
                @endcan
                @can('update', $notification)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.edit', $notification) }}">Edit</a>
                @endcan
            </div>
        </div>

        {{-- Plain text only: escaped by {{ }}, line breaks preserved by CSS (no raw HTML). --}}
        <div class="mt-6 whitespace-pre-line break-words rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-800">{{ $notification->message }}</div>

        <dl class="mt-6 grid gap-2 text-sm sm:grid-cols-3">
            <div class="feature-item"><dt class="text-xs text-slate-500">Sent</dt><dd class="mt-1 font-medium">{{ $notification->created_at?->format('d M Y, H:i') }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Sent by</dt><dd class="mt-1 font-medium">{{ $notification->creator?->name ?? 'System' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Read at</dt><dd class="mt-1 font-medium">{{ $notification->read_at?->format('d M Y, H:i') ?? 'Not read yet' }}</dd></div>
        </dl>

        @can('delete', $notification)
            <form class="mt-6" method="POST" action="{{ route('notifications.destroy', $notification) }}" onsubmit="return confirm(@js('Delete the notification "'.$notification->title.'"? This cannot be undone.'))">
                @csrf
                @method('DELETE')
                <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete notification</button>
            </form>
        @endcan
    </div>
</div>
@endsection
