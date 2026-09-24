@extends('layouts.app')

@section('title', 'Circular')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-indigo-50 px-3 py-1 font-mono text-xs font-semibold text-indigo-700">{{ $circular->circular_number }}</span>
                    @include('communication.partials.badge', ['kind' => 'status', 'value' => $circular->status])
                    @if($circular->isPublished())
                        @include('communication.partials.badge', ['kind' => 'visibility', 'value' => $circular->visibility()])
                    @endif
                </div>
                <h2 class="mt-3 break-words text-2xl font-bold tracking-tight">{{ $circular->title }}</h2>
                <p class="panel-subtitle break-words"><span class="font-medium text-slate-600">Subject:</span> {{ $circular->subject }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.index') }}">Back</a>
                @can('update', $circular)
                    @unless($circular->isArchived())
                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.edit', $circular) }}">Edit</a>
                    @endunless
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="feature-item"><dt class="text-xs text-slate-500">Issue date</dt><dd class="mt-1 font-medium">{{ $circular->issue_date?->format('d M Y') }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Audience</dt><dd class="mt-1 font-medium">{{ $circular->targetLabel() }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Publish at</dt><dd class="mt-1 font-medium">{{ $circular->publish_at?->format('d M Y, H:i') ?? 'On publication' }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Expires at</dt><dd class="mt-1 font-medium">{{ $circular->expires_at?->format('d M Y, H:i') ?? 'No expiry' }}</dd></div>
        </dl>
    </div>

    <div class="panel">
        <h3 class="panel-title">Content</h3>
        {{-- Plain text only: escaped by {{ }}, line breaks preserved by CSS (no raw HTML). --}}
        <div class="mt-4 whitespace-pre-line break-words text-sm leading-6 text-slate-800">{{ $circular->content }}</div>

        @if($circular->hasAttachment())
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 text-sm">
                <span>📎 <strong>{{ $circular->attachment_name }}</strong> <span class="text-slate-500">({{ $circular->attachmentSizeLabel() }})</span></span>
                @can('download', $circular)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.attachment', $circular) }}">Download</a>
                @endcan
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="panel-title">Workflow</h3>
            <p class="panel-subtitle">Draft → Published → Archived. Publishing without a publish date makes the circular effective immediately.</p>
            @can('publish', $circular)
                <div class="mt-4 flex flex-wrap gap-2">
                    @if($circular->canTransition('publish'))
                        <form method="POST" action="{{ route('circulars.publish', $circular) }}">
                            @csrf
                            <button class="button" type="submit">Publish</button>
                        </form>
                    @endif
                    @if($circular->canTransition('unpublish'))
                        <form method="POST" action="{{ route('circulars.unpublish', $circular) }}">
                            @csrf
                            <button class="button !bg-slate-200 !text-slate-700" type="submit">{{ $circular->isArchived() ? 'Restore to draft' : 'Unpublish' }}</button>
                        </form>
                    @endif
                    @if($circular->canTransition('archive'))
                        <form method="POST" action="{{ route('circulars.archive', $circular) }}" onsubmit="return confirm(@js('Archive circular '.$circular->circular_number.'? Archived circulars are read-only.'))">
                            @csrf
                            <button class="button !bg-amber-100 !text-amber-800" type="submit">Archive</button>
                        </form>
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">You do not have permission to publish, unpublish or archive circulars.</p>
            @endcan
        </div>

        <div class="panel">
            <h3 class="panel-title">Record</h3>
            <dl class="mt-4 grid gap-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Created by</dt><dd>{{ $circular->creator?->name ?? '—' }} · {{ $circular->created_at?->format('d M Y, H:i') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Last updated by</dt><dd>{{ $circular->updater?->name ?? '—' }} · {{ $circular->updated_at?->format('d M Y, H:i') }}</dd></div>
            </dl>
            @can('delete', $circular)
                <form class="mt-4" method="POST" action="{{ route('circulars.destroy', $circular) }}" onsubmit="return confirm(@js('Delete circular '.$circular->circular_number.'? It will be archived (soft-deleted); the number stays reserved.'))">
                    @csrf
                    @method('DELETE')
                    <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete circular</button>
                </form>
            @endcan
        </div>
    </div>
</div>
@endsection
