@extends('layouts.app')

@section('title', 'Communication Log')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @include('communication.partials.badge', ['kind' => 'channel', 'value' => $log->channel, 'label' => $log->channelLabel()])
                    @include('communication.partials.badge', ['kind' => 'log', 'value' => $log->status, 'label' => $log->statusLabel()])
                </div>
                <h2 class="mt-3 break-words text-2xl font-bold tracking-tight">{{ $log->subject ?? $log->channelLabel().' to '.$log->recipient }}</h2>
                <p class="panel-subtitle">To {{ $log->recipient }}@if($recipientLabel) · {{ $recipientLabel }}@endif</p>
            </div>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-logs.index') }}">Back</a>
        </div>

        {{-- Plain text only: escaped by {{ }}, line breaks preserved by CSS. --}}
        <div class="mt-6 whitespace-pre-line break-words rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-800">{{ $log->content }}</div>

        @if($log->isFailed() && $log->failure_reason)
            <div class="alert-error mt-4">{{ $log->failure_reason }}</div>
        @endif

        <dl class="mt-6 grid gap-2 text-sm sm:grid-cols-3">
            <div class="feature-item"><dt class="text-xs text-slate-500">Logged</dt><dd class="mt-1 font-medium">{{ $log->created_at?->format('d M Y, H:i') }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Sent at</dt><dd class="mt-1 font-medium">{{ $log->sent_at?->format('d M Y, H:i') ?? 'Not sent yet' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Delivered at</dt><dd class="mt-1 font-medium">{{ $log->delivered_at?->format('d M Y, H:i') ?? 'Not delivered yet' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Template</dt><dd class="mt-1 font-medium">{{ $log->template ? $log->template->name.' ('.$log->template->code.')' : 'Ad-hoc message' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Provider reference</dt><dd class="mt-1 break-words font-medium">{{ $log->provider_reference ?? '—' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Logged by</dt><dd class="mt-1 font-medium">{{ $log->creator?->name ?? 'System' }}</dd></div>
        </dl>

        <p class="mt-6 text-xs text-slate-500">Communication logs are immutable: they cannot be edited or deleted from the UI.</p>
    </div>
</div>
@endsection
