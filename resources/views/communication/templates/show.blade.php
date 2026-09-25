@extends('layouts.app')

@section('title', 'Template')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @include('communication.partials.badge', ['kind' => 'channel', 'value' => $template->channel, 'label' => $template->channelLabel()])
                    @include('communication.partials.badge', ['kind' => 'template', 'value' => $template->status, 'label' => ucfirst($template->status)])
                    <span class="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs font-semibold text-slate-700">{{ $template->code }}</span>
                </div>
                <h2 class="mt-3 break-words text-2xl font-bold tracking-tight">{{ $template->name }}</h2>
                <p class="panel-subtitle">Reusable definition — this screen never sends a message.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.index') }}">Back</a>
                @can('update', $template)
                    <a class="button" href="{{ route('communication-templates.edit', $template) }}">Edit</a>
                @endcan
            </div>
        </div>

        @if($template->subject)
            <p class="mt-6 text-sm"><span class="text-xs uppercase tracking-wide text-slate-500">Subject</span><br><span class="font-medium">{{ $template->subject }}</span></p>
        @endif

        {{-- Plain text only: escaped by {{ }}, line breaks preserved by CSS. --}}
        <div class="mt-4 whitespace-pre-line break-words rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-800">{{ $template->body }}</div>

        @if($template->placeholders())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($template->placeholders() as $placeholder)
                    <span class="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs text-slate-700">{{ $placeholder }}</span>
                @endforeach
            </div>
        @endif

        <dl class="mt-6 grid gap-2 text-sm sm:grid-cols-3">
            <div class="feature-item"><dt class="text-xs text-slate-500">Created</dt><dd class="mt-1 font-medium">{{ $template->created_at?->format('d M Y, H:i') }} · {{ $template->creator?->name ?? 'System' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Last updated</dt><dd class="mt-1 font-medium">{{ $template->updated_at?->format('d M Y, H:i') }} · {{ $template->updater?->name ?? '—' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Channel</dt><dd class="mt-1 font-medium">{{ $template->channelLabel() }}{{ $template->isSms() ? ' (no subject line)' : '' }}</dd></div>
        </dl>

        @can('delete', $template)
            <form class="mt-6" method="POST" action="{{ route('communication-templates.destroy', $template) }}" onsubmit="return confirm(@js('Delete the template "'.$template->name.'"? Existing logs keep their own copy of the message.'))">
                @csrf
                @method('DELETE')
                <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete template</button>
            </form>
        @endcan
    </div>
</div>
@endsection
