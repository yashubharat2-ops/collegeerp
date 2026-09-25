@extends('layouts.app')

@section('title', 'Notice')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @include('communication.partials.badge', ['kind' => 'status', 'value' => $notice->status])
                    @if($notice->isPublished())
                        @include('communication.partials.badge', ['kind' => 'visibility', 'value' => $notice->visibility()])
                    @endif
                    @include('communication.partials.badge', ['kind' => 'priority', 'value' => $notice->priority])
                </div>
                <h2 class="mt-3 break-words text-2xl font-bold tracking-tight">{{ $notice->title }}</h2>
                <p class="panel-subtitle break-all font-mono">{{ $notice->slug }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.index') }}">Back</a>
                @can('update', $notice)
                    @unless($notice->isArchived())
                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.edit', $notice) }}">Edit</a>
                    @endunless
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="feature-item"><dt class="text-xs text-slate-500">Type</dt><dd class="mt-1 font-medium">{{ $notice->typeLabel() }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Audience</dt><dd class="mt-1 font-medium">{{ $notice->targetLabel() }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Publish at</dt><dd class="mt-1 font-medium">{{ $notice->publish_at?->format('d M Y, H:i') }}</dd></div>
            <div class="feature-item"><dt class="text-xs text-slate-500">Expires at</dt><dd class="mt-1 font-medium">{{ $notice->expires_at?->format('d M Y, H:i') ?? 'No expiry' }}</dd></div>
        </dl>
    </div>

    <div class="panel">
        <h3 class="panel-title">Content</h3>
        {{-- Plain text only: escaped by {{ }}, line breaks preserved by CSS (no raw HTML). --}}
        <div class="mt-4 whitespace-pre-line break-words text-sm leading-6 text-slate-800">{{ $notice->content }}</div>

        @if($notice->hasAttachment())
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 text-sm">
                <span>📎 <strong>{{ $notice->attachment_name }}</strong> <span class="text-slate-500">({{ $notice->attachmentSizeLabel() }})</span></span>
                @can('download', $notice)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.attachment', $notice) }}">Download</a>
                @endcan
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="panel-title">Workflow</h3>
            <p class="panel-subtitle">Draft → Published → Archived. Publishing requires the notice not to have expired.</p>
            @can('publish', $notice)
                <div class="mt-4 flex flex-wrap gap-2">
                    @if($notice->canTransition('publish'))
                        <form method="POST" action="{{ route('notices.publish', $notice) }}">
                            @csrf
                            <button class="button" type="submit">Publish</button>
                        </form>
                    @endif
                    @if($notice->canTransition('unpublish'))
                        <form method="POST" action="{{ route('notices.unpublish', $notice) }}">
                            @csrf
                            <button class="button !bg-slate-200 !text-slate-700" type="submit">{{ $notice->isArchived() ? 'Restore to draft' : 'Unpublish' }}</button>
                        </form>
                    @endif
                    @if($notice->canTransition('archive'))
                        <form method="POST" action="{{ route('notices.archive', $notice) }}" onsubmit="return confirm(@js('Archive the notice "'.$notice->title.'"? Archived notices are read-only.'))">
                            @csrf
                            <button class="button !bg-amber-100 !text-amber-800" type="submit">Archive</button>
                        </form>
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">You do not have permission to publish, unpublish or archive notices.</p>
            @endcan
        </div>

        <div class="panel">
            <h3 class="panel-title">Record</h3>
            <dl class="mt-4 grid gap-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Created by</dt><dd>{{ $notice->creator?->name ?? '—' }} · {{ $notice->created_at?->format('d M Y, H:i') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Last updated by</dt><dd>{{ $notice->updater?->name ?? '—' }} · {{ $notice->updated_at?->format('d M Y, H:i') }}</dd></div>
            </dl>
            @can('delete', $notice)
                <form class="mt-4" method="POST" action="{{ route('notices.destroy', $notice) }}" onsubmit="return confirm(@js('Delete the notice "'.$notice->title.'"? It will be archived (soft-deleted) and can be restored by an administrator.'))">
                    @csrf
                    @method('DELETE')
                    <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete notice</button>
                </form>
            @endcan
        </div>
    </div>
</div>
@endsection
