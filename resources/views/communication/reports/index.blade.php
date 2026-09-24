@extends('layouts.app')

@section('title', 'Communication Reports')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Communication Reports</h2>
                <p class="panel-subtitle">Read-only, live figures for the active college: notices, circulars, notifications and SMS / e-mail logs. Everything is aggregated from the existing records — there are no reporting tables and nothing on this screen writes.</p>
            </div>
        </div>

        <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('communication-reports.index') }}">
            <div>
                <label class="label" for="date_from">From</label>
                <input class="input" id="date_from" name="date_from" type="date" value="{{ $filters['date_from']?->format('Y-m-d') }}">
            </div>
            <div>
                <label class="label" for="date_to">To</label>
                <input class="input" id="date_to" name="date_to" type="date" value="{{ $filters['date_to']?->format('Y-m-d') }}">
            </div>
            <div class="flex items-end gap-2 sm:col-span-2">
                <button class="button" type="submit">Apply</button>
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-reports.index') }}">Reset</a>
            </div>
        </form>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Total notices</p>
                <p class="stat-value" data-stat="notices">{{ $summary['notices']['total'] }}</p>
                <p class="stat-hint">{{ $summary['notices']['draft'] }} draft · {{ $summary['notices']['archived'] }} archived</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Published notices</p>
                <p class="stat-value" data-stat="published_notices">{{ $summary['notices']['published'] }}</p>
                <p class="stat-hint">live announcements</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Circulars</p>
                <p class="stat-value" data-stat="circulars">{{ $summary['circulars']['total'] }}</p>
                <p class="stat-hint">{{ $summary['circulars']['published'] }} published · {{ $summary['circulars']['draft'] }} draft</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Notifications</p>
                <p class="stat-value" data-stat="notifications">{{ $summary['notifications']['total'] }}</p>
                <p class="stat-hint">{{ $summary['notifications']['delivered'] }} delivered</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Read notifications</p>
                <p class="stat-value" data-stat="read_notifications">{{ $summary['notifications']['read'] }}</p>
                <p class="stat-hint">{{ $summary['notifications']['unread'] }} unread</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">SMS messages</p>
                <p class="stat-value" data-stat="sms">{{ $summary['sms']['total'] }}</p>
                <p class="stat-hint">{{ $summary['sms']['delivered'] }} delivered · {{ $summary['sms']['failed'] }} failed</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Email messages</p>
                <p class="stat-value" data-stat="email">{{ $summary['email']['total'] }}</p>
                <p class="stat-hint">{{ $summary['email']['delivered'] }} delivered · {{ $summary['email']['failed'] }} failed</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Failed communications</p>
                <p class="stat-value" data-stat="failed_communications">{{ $summary['failed_communications'] }}</p>
                <p class="stat-hint">of {{ $summary['total_communications'] }} logged</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3 class="panel-title">SMS / Email by status</h3>
        <p class="panel-subtitle">Counts come straight from the communication logs of the active college.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Channel</th>
                        <th>Queued</th>
                        <th>Sent</th>
                        <th>Delivered</th>
                        <th>Failed</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(['sms' => 'SMS', 'email' => 'Email'] as $channel => $channelLabel)
                        <tr class="border-b" data-channel="{{ $channel }}">
                            <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'channel', 'value' => $channel, 'label' => $channelLabel])</td>
                            <td class="pr-3" data-status="queued">{{ $summary[$channel]['queued'] }}</td>
                            <td class="pr-3" data-status="sent">{{ $summary[$channel]['sent'] }}</td>
                            <td class="pr-3" data-status="delivered">{{ $summary[$channel]['delivered'] }}</td>
                            <td class="pr-3" data-status="failed">{{ $summary[$channel]['failed'] }}</td>
                            <td class="font-semibold" data-status="total">{{ $summary[$channel]['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="panel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="panel-title">Recent SMS / email activity</h3>
                    <p class="panel-subtitle">The latest logged messages of this college.</p>
                </div>
                @if($canLogs)
                    <a class="text-sm font-medium text-indigo-600 hover:underline" href="{{ route('communication-logs.index') }}">View all</a>
                @endif
            </div>
            @if(! $canLogs)
                <p class="mt-4 text-sm text-slate-500">You do not have permission to view communication logs.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-slate-500">
                                <th class="py-2">Recipient</th>
                                <th>Channel</th>
                                <th>Status</th>
                                <th>Logged</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentLogs as $log)
                                <tr class="border-b">
                                    <td class="max-w-[14rem] break-words py-2 pr-3 font-medium">
                                        <a class="text-indigo-600 hover:underline" href="{{ route('communication-logs.show', $log) }}">{{ $log->recipient }}</a>
                                    </td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'channel', 'value' => $log->channel, 'label' => $log->channelLabel()])</td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'log', 'value' => $log->status, 'label' => $log->statusLabel()])</td>
                                    <td class="whitespace-nowrap">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-slate-500" colspan="4">No SMS or e-mail communications logged yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="panel">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="panel-title">Recent notification activity</h3>
                    <p class="panel-subtitle">The latest internal notifications with their tracking state.</p>
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
                                <th>State</th>
                                <th>Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentNotifications as $notification)
                                <tr class="border-b">
                                    <td class="max-w-[14rem] break-words py-2 pr-3 font-medium">{{ $notification->title }}</td>
                                    <td class="pr-3">{{ $recipientLabels[$notification->recipient_type.':'.$notification->recipient_id] ?? 'Unavailable recipient' }}</td>
                                    <td class="pr-3">@include('communication.partials.badge', ['kind' => 'delivery', 'value' => $notification->deliveryState(), 'label' => $notification->deliveryStateLabel()])</td>
                                    <td class="whitespace-nowrap">{{ $notification->created_at?->format('d M Y, H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-slate-500" colspan="4">No notifications sent yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
