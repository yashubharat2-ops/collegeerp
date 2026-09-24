@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Notifications</h2>
            <p class="panel-subtitle">Internal, in-app notifications of the active college, addressed to existing users, students or staff members. No SMS, e-mail or WhatsApp is sent.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($myUnread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="button !bg-slate-200 !text-slate-700" type="submit">Mark all mine as read ({{ $myUnread }})</button>
                </form>
            @endif
            @can('create', App\Models\CommunicationNotification::class)
                <a class="button" href="{{ route('notifications.create') }}">+ Send notification</a>
            @endcan
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 text-sm">
        <a class="rounded-full px-3 py-1 {{ $filters['scope'] === 'mine' ? 'bg-slate-100 text-slate-700' : 'bg-indigo-600 font-semibold text-white' }}" href="{{ route('notifications.index') }}">All notifications</a>
        <a class="rounded-full px-3 py-1 {{ $filters['scope'] === 'mine' ? 'bg-indigo-600 font-semibold text-white' : 'bg-slate-100 text-slate-700' }}" href="{{ route('notifications.index', ['scope' => 'mine']) }}">Addressed to me{{ $myUnread > 0 ? ' · '.$myUnread.' unread' : '' }}</a>
    </div>

    <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8" method="GET" action="{{ route('notifications.index') }}">
        @if($filters['scope'])
            <input type="hidden" name="scope" value="{{ $filters['scope'] }}">
        @endif
        <div class="sm:col-span-2">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Title or message" maxlength="100">
        </div>
        <div>
            <label class="label" for="read_status">State</label>
            <select class="input" id="read_status" name="read_status">
                <option value="">Read & unread</option>
                <option value="unread" @selected($filters['read_status'] === 'unread')>Unread</option>
                <option value="read" @selected($filters['read_status'] === 'read')>Read</option>
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
            <label class="label" for="notification_type">Type</label>
            <input class="input" id="notification_type" name="notification_type" type="text" list="notification-type-options" value="{{ $filters['notification_type'] }}" placeholder="Any type" maxlength="50">
            <datalist id="notification-type-options">
                @foreach($types as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                @endforeach
            </datalist>
        </div>
        <div>
            <label class="label" for="recipient_type">Recipient type</label>
            <select class="input" id="recipient_type" name="recipient_type">
                <option value="">All recipients</option>
                @foreach($recipientTypes as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}" @selected($filters['recipient_type'] === $typeValue)>{{ $typeLabel }}</option>
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
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4 xl:col-span-8">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.index', array_filter(['scope' => $filters['scope']])) }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Notification</th>
                    <th>Recipient</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>State</th>
                    <th>Sent</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notifications as $notification)
                    <tr class="border-b align-top {{ $notification->isRead() ? '' : 'bg-indigo-50/40' }}">
                        <td class="max-w-md py-2 pr-3">
                            <a class="font-medium text-indigo-600 hover:underline" href="{{ route('notifications.show', $notification) }}">{{ $notification->title }}</a>
                            <p class="mt-0.5 line-clamp-2 break-words text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($notification->message, 140) }}</p>
                        </td>
                        <td class="py-2 pr-3">
                            <span class="text-xs text-slate-500">{{ $notification->recipientTypeLabel() }}</span><br>
                            {{ $recipientLabels[$notification->recipient_type.':'.$notification->recipient_id] ?? 'Unavailable recipient' }}
                            @if($notification->isAddressedTo(auth()->user()))
                                <span class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">you</span>
                            @endif
                        </td>
                        <td class="py-2 pr-3">{{ $notification->typeLabel() }}</td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'priority', 'value' => $notification->priority])</td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'read', 'value' => $notification->isRead() ? 'read' : 'unread'])</td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $notification->created_at?->format('d M Y, H:i') }}</td>
                        <td class="py-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                @can('markRead', $notification)
                                    @if($notification->isRead())
                                        <form method="POST" action="{{ route('notifications.unread', $notification) }}">
                                            @csrf
                                            <button class="button !bg-slate-200 !text-slate-700" type="submit">Mark unread</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                            @csrf
                                            <button class="button !bg-slate-200 !text-slate-700" type="submit">Mark read</button>
                                        </form>
                                    @endif
                                @endcan
                                @can('update', $notification)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('notifications.edit', $notification) }}">Edit</a>
                                @endcan
                                @can('delete', $notification)
                                    <form method="POST" action="{{ route('notifications.destroy', $notification) }}" onsubmit="return confirm(@js('Delete the notification "'.$notification->title.'"? This cannot be undone.'))">
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
@endsection
