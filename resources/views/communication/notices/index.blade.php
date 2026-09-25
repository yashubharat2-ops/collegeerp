@extends('layouts.app')

@section('title', 'Notices / Announcements')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Notices / Announcements</h2>
            <p class="panel-subtitle">Announcements of the active college. New notices start as drafts; publishing, unpublishing and archiving are separate, permission-controlled actions. Scheduled and expired states are derived from the publish and expiry dates.</p>
        </div>
        @can('create', App\Models\Notice::class)
            <a class="button" href="{{ route('notices.create') }}">+ New notice</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8" method="GET" action="{{ route('notices.index') }}">
        <div class="sm:col-span-2">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Title or content" maxlength="100">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="priority">Priority</label>
            <select class="input" id="priority" name="priority">
                <option value="">All priorities</option>
                @foreach($priorities as $priorityOption)
                    <option value="{{ $priorityOption }}" @selected($filters['priority'] === $priorityOption)>{{ ucfirst($priorityOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="notice_type">Type</label>
            <input class="input" id="notice_type" name="notice_type" type="text" list="notice-type-options" value="{{ $filters['notice_type'] }}" placeholder="Any type" maxlength="50">
            <datalist id="notice-type-options">
                @foreach($types as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                @endforeach
            </datalist>
        </div>
        <div>
            <label class="label" for="target_type">Audience</label>
            <select class="input" id="target_type" name="target_type">
                <option value="">All audiences</option>
                @foreach($targets as $targetValue => $targetLabel)
                    <option value="{{ $targetValue }}" @selected($filters['target_type'] === $targetValue)>{{ $targetLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="date_from">Publish from</label>
            <input class="input" id="date_from" name="date_from" type="date" value="{{ $filters['date_from']?->format('Y-m-d') }}">
        </div>
        <div>
            <label class="label" for="date_to">Publish to</label>
            <input class="input" id="date_to" name="date_to" type="date" value="{{ $filters['date_to']?->format('Y-m-d') }}">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4 xl:col-span-8">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Title</th>
                    <th>Type</th>
                    <th>Audience</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Publish at</th>
                    <th>Expires</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $notice)
                    <tr class="border-b align-top">
                        <td class="max-w-xs py-2 pr-3">
                            <a class="font-medium text-indigo-600 hover:underline" href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a>
                            <p class="mt-0.5 break-all font-mono text-xs text-slate-400">{{ $notice->slug }}</p>
                            @if($notice->hasAttachment())
                                <p class="mt-0.5 text-xs text-slate-500">📎 attachment</p>
                            @endif
                        </td>
                        <td class="py-2 pr-3">{{ $notice->typeLabel() }}</td>
                        <td class="py-2 pr-3">{{ $targets[$notice->target_type] ?? $notice->target_type }}</td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'priority', 'value' => $notice->priority])</td>
                        <td class="py-2 pr-3">
                            <div class="flex flex-wrap gap-1">
                                @include('communication.partials.badge', ['kind' => 'status', 'value' => $notice->status])
                                @if($notice->isPublished())
                                    @include('communication.partials.badge', ['kind' => 'visibility', 'value' => $notice->visibility()])
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $notice->publish_at?->format('d M Y, H:i') }}</td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $notice->expires_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="py-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.show', $notice) }}">View</a>
                                @can('update', $notice)
                                    @unless($notice->isArchived())
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.edit', $notice) }}">Edit</a>
                                    @endunless
                                @endcan
                                @can('delete', $notice)
                                    <form method="POST" action="{{ route('notices.destroy', $notice) }}" onsubmit="return confirm(@js('Delete the notice "'.$notice->title.'"? It will be archived (soft-deleted) and can be restored by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-slate-500" colspan="8">
                            @if(array_filter($filters, fn ($value) => filled($value)))
                                No notices match these filters.
                            @else
                                No notices have been created for this college yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $notices->links() }}</div>
</div>
@endsection
