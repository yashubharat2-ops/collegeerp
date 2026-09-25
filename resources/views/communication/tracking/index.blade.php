@extends('layouts.app')

@section('title', 'Delivery / Read Tracking')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Delivery / Read Tracking</h2>
                <p class="panel-subtitle">Sent, delivered and read state of the active college's existing notifications — derived from the notification records themselves, never duplicated. SMS / e-mail delivery counters come from the communication logs.</p>
            </div>
            @if($canViewLogs)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-logs.index') }}">SMS / Email logs</a>
            @endif
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Notifications sent</p>
                <p class="stat-value" data-stat="sent">{{ $totals['sent'] }}</p>
                <p class="stat-hint">{{ $totals['total'] }} in total</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Delivered</p>
                <p class="stat-value" data-stat="delivered">{{ $totals['delivered'] }}</p>
                <p class="stat-hint">{{ $totals['undelivered'] }} awaiting delivery</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Read</p>
                <p class="stat-value" data-stat="read">{{ $totals['read'] }}</p>
                <p class="stat-hint">{{ $totals['unread'] }} unread</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">SMS / Email delivered</p>
                <p class="stat-value" data-stat="log_delivered">{{ $logTotals['delivered'] }}</p>
                <p class="stat-hint">{{ $logTotals['failed'] }} failed · {{ $logTotals['total'] }} logged</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" method="GET" action="{{ route('communication-tracking.index') }}">
            <div class="sm:col-span-2">
                <label class="label" for="search">Search</label>
                <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Title or message" maxlength="100">
            </div>
            <div>
                <label class="label" for="state">Tracking state</label>
                <select class="input" id="state" name="state">
                    <option value="">All states</option>
                    @foreach($states as $value => $label)
                        <option value="{{ $value }}" @selected($filters['state'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="recipient_type">Recipient type</label>
                <select class="input" id="recipient_type" name="recipient_type">
                    <option value="">All recipients</option>
                    @foreach($recipientTypes as $value => $label)
                        <option value="{{ $value }}" @selected($filters['recipient_type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="date_from">Sent from</label>
                <input class="input" id="date_from" name="date_from" type="date" value="{{ $filters['date_from']?->format('Y-m-d') }}">
            </div>
            <div>
                <label class="label" for="date_to">Sent to</label>
                <input class="input" id="date_to" name="date_to" type="date" value="{{ $filters['date_to']?->format('Y-m-d') }}">
            </div>
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3 xl:col-span-6">
                <button class="button" type="submit">Filter</button>
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-tracking.index') }}">Reset</a>
            </div>
        </form>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Notification</th>
                        <th>Recipient</th>
                        <th>State</th>
                        <th>Sent at</th>
                        <th>Delivered at</th>
                        <th>Read at</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($notifications as $notification)
                        <tr class="border-b align-top">
                            <td class="max-w-md py-2 pr-3">
                                <span class="font-medium">{{ $notification->title }}</span>
                                <p class="mt-0.5 line-clamp-2 break-words text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($notification->message, 120) }}</p>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="text-xs text-slate-500">{{ $notification->recipientTypeLabel() }}</span><br>
                                {{ $recipientLabels[$notification->recipient_type.':'.$notification->recipient_id] ?? 'Unavailable recipient' }}
                            </td>
                            <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'delivery', 'value' => $notification->deliveryState(), 'label' => $notification->deliveryStateLabel()])</td>
                            <td class="whitespace-nowrap py-2 pr-3">{{ $notification->sent_at?->format('d M Y, H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap py-2 pr-3">{{ $notification->delivered_at?->format('d M Y, H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap py-2 pr-3">{{ $notification->read_at?->format('d M Y, H:i') ?? '—' }}</td>
                            <td class="py-2">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @can('markDelivered', $notification)
                                        @unless($notification->isDelivered())
                                            <form method="POST" action="{{ route('communication-tracking.delivered', $notification) }}">
                                                @csrf
                                                <button class="button !bg-slate-200 !text-slate-700" type="submit">Mark delivered</button>
                                            </form>
                                        @endunless
                                    @endcan
                                    @can('view', $notification)
                                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.show', $notification) }}">Open</a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-center text-slate-500" colspan="7">
                                @if(array_filter($filters, fn ($value) => filled($value)))
                                    No notifications match these filters.
                                @else
                                    No notifications have been sent in this college yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">{{ $notifications->links() }}</div>
    </div>
</div>
@endsection
