@extends('layouts.app')

@section('title', 'Communication Dashboard')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Communication Dashboard</h2>
                <p class="panel-subtitle">Live, read-only overview of the active college's notices, circulars and internal notifications. Every figure is computed from the records themselves; nothing here is stored separately. Archived (deleted) records are excluded.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($canNotices)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notices.index') }}">Notices</a>
                @endif
                @if($canCirculars)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('circulars.index') }}">Circulars</a>
                @endif
                @if($canNotifications)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.index') }}">Notifications</a>
                @endif
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Total notices</p>
                <p class="stat-value" data-stat="notices">{{ $totals['notices'] }}</p>
                <p class="stat-hint">{{ $totals['live_notices'] }} currently live</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Published notices</p>
                <p class="stat-value" data-stat="published_notices">{{ $totals['published_notices'] }}</p>
                <p class="stat-hint">{{ $totals['archived_notices'] }} archived</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Draft notices</p>
                <p class="stat-value" data-stat="draft_notices">{{ $totals['draft_notices'] }}</p>
                <p class="stat-hint">awaiting publication</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Total circulars</p>
                <p class="stat-value" data-stat="circulars">{{ $totals['circulars'] }}</p>
                <p class="stat-hint">{{ $totals['draft_circulars'] }} draft · {{ $totals['archived_circulars'] }} archived</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Published circulars</p>
                <p class="stat-value" data-stat="published_circulars">{{ $totals['published_circulars'] }}</p>
                <p class="stat-hint">formally issued</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Total notifications</p>
                <p class="stat-value" data-stat="notifications">{{ $totals['notifications'] }}</p>
                <p class="stat-hint">internal, in-app only</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Unread notifications</p>
                <p class="stat-value" data-stat="unread_notifications">{{ $totals['unread_notifications'] }}</p>
                <p class="stat-hint">{{ $totals['my_unread_notifications'] }} addressed to you</p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="panel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="panel-title">Recent notices</h3>
                    <p class="panel-subtitle">The latest notices / announcements created in this college.</p>
                </div>
                @if($canNotices)
                    <a class="text-sm font-medium text-indigo-600 hover:underline" href="{{ route('notices.index') }}">View all</a>
                @endif
            </div>
            @if(! $canNotices)
                <p class="mt-4 text-sm text-slate-500">You do not have permission to view notices.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-slate-500">
                                <th class="py-2">Title</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Publish at</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentNotices as $notice)
                                <tr class="border-b">
                                    <td class="py-2 pr-3 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a></td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'priority', 'value' => $notice->priority])</td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'status', 'value' => $notice->status])</td>
                                    <td class="whitespace-nowrap">{{ $notice->publish_at?->format('d M Y, H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-slate-500" colspan="4">No notices yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="panel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="panel-title">Recent circulars</h3>
                    <p class="panel-subtitle">The latest circulars created in this college.</p>
                </div>
                @if($canCirculars)
                    <a class="text-sm font-medium text-indigo-600 hover:underline" href="{{ route('circulars.index') }}">View all</a>
                @endif
            </div>
            @if(! $canCirculars)
                <p class="mt-4 text-sm text-slate-500">You do not have permission to view circulars.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-slate-500">
                                <th class="py-2">Number</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Issue date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentCirculars as $circular)
                                <tr class="border-b">
                                    <td class="py-2 pr-3"><span class="font-mono text-xs">{{ $circular->circular_number }}</span></td>
                                    <td class="pr-3 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('circulars.show', $circular) }}">{{ $circular->title }}</a></td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'status', 'value' => $circular->status])</td>
                                    <td class="whitespace-nowrap">{{ $circular->issue_date?->format('d M Y') }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-slate-500" colspan="4">No circulars yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="panel">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="panel-title">Recent notifications</h3>
                <p class="panel-subtitle">The latest internal notifications sent in this college.</p>
            </div>
            @if($canNotifications)
                <a class="text-sm font-medium text-indigo-600 hover:underline" href="{{ route('notifications.index') }}">View all</a>
            @endif
        </div>
        @if(! $canNotifications)
            <p class="mt-4 text-sm text-slate-500">You do not have permission to view notifications.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-2">Title</th>
                            <th>Recipient</th>
                            <th>Priority</th>
                            <th>State</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentNotifications as $notification)
                            <tr class="border-b">
                                <td class="py-2 pr-3 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('notifications.show', $notification) }}">{{ $notification->title }}</a></td>
                                <td class="pr-3">{{ $notification->recipientTypeLabel() }}: {{ $recipientLabels[$notification->recipient_type.':'.$notification->recipient_id] ?? 'Unavailable recipient' }}</td>
                                <td class="pr-3">@include('communication.partials.badge', ['kind' => 'priority', 'value' => $notification->priority])</td>
                                <td class="pr-3">@include('communication.partials.badge', ['kind' => 'read', 'value' => $notification->isRead() ? 'read' : 'unread'])</td>
                                <td class="whitespace-nowrap">{{ $notification->created_at?->format('d M Y, H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="5">No notifications yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
