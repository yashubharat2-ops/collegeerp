@extends('layouts.app')

@section('title', 'SMS / Email Logs')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">SMS / Email Logs</h2>
            <p class="panel-subtitle">Immutable record of every SMS / e-mail message of the active college. Logs cannot be edited or deleted from the UI. No external gateway is connected — statuses are recorded, not fetched.</p>
        </div>
    </div>

    <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" method="GET" action="{{ route('communication-logs.index') }}">
        <div class="sm:col-span-2">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Recipient, subject or reference" maxlength="100">
        </div>
        <div>
            <label class="label" for="channel">Channel</label>
            <select class="input" id="channel" name="channel">
                <option value="">All channels</option>
                @foreach($channels as $value => $label)
                    <option value="{{ $value }}" @selected($filters['channel'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="template_id">Template</label>
            <select class="input" id="template_id" name="template_id">
                <option value="">All templates</option>
                @foreach($templates as $template)
                    <option value="{{ $template->id }}" @selected($filters['template_id'] === $template->id)>{{ $template->name }} ({{ $template->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="date_from">Logged from</label>
            <input class="input" id="date_from" name="date_from" type="date" value="{{ $filters['date_from']?->format('Y-m-d') }}">
        </div>
        <div>
            <label class="label" for="date_to">Logged to</label>
            <input class="input" id="date_to" name="date_to" type="date" value="{{ $filters['date_to']?->format('Y-m-d') }}">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3 xl:col-span-6">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-logs.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Recipient</th>
                    <th>Channel</th>
                    <th>Message</th>
                    <th>Template</th>
                    <th>Status</th>
                    <th>Sent</th>
                    <th>Delivered</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr class="border-b align-top">
                        <td class="max-w-xs break-words py-2 pr-3 font-medium">{{ $log->recipient }}</td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'channel', 'value' => $log->channel, 'label' => $log->channelLabel()])</td>
                        <td class="max-w-md py-2 pr-3">
                            @if($log->subject)
                                <span class="font-medium">{{ $log->subject }}</span><br>
                            @endif
                            <span class="line-clamp-2 break-words text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($log->content, 140) }}</span>
                        </td>
                        <td class="py-2 pr-3 text-xs">{{ $log->template?->code ?? '—' }}</td>
                        <td class="py-2 pr-3">
                            @include('communication.partials.badge', ['kind' => 'log', 'value' => $log->status, 'label' => $log->statusLabel()])
                            @if($log->isFailed() && $log->failure_reason)
                                <p class="mt-1 max-w-[16rem] break-words text-xs text-rose-600">{{ \Illuminate\Support\Str::limit($log->failure_reason, 80) }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $log->sent_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $log->delivered_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="py-2 text-right">
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-logs.show', $log) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-slate-500" colspan="8">
                            @if(array_filter($filters, fn ($value) => filled($value)))
                                No communication logs match these filters.
                            @else
                                No SMS or e-mail communications have been logged in this college yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $logs->links() }}</div>
</div>
@endsection
