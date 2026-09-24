@extends('layouts.app')

@section('title', 'SMS / Email Templates')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">SMS / Email Templates</h2>
            <p class="panel-subtitle">Reusable message definitions of the active college. Codes are unique per college. Nothing is sent from this screen — no external SMS or e-mail provider is connected.</p>
        </div>
        @can('create', App\Models\CommunicationTemplate::class)
            <a class="button" href="{{ route('communication-templates.create') }}">+ New template</a>
        @endcan
    </div>

    <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('communication-templates.index') }}">
        <div class="sm:col-span-2">
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Name, code or subject" maxlength="100">
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
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Template</th>
                    <th>Code</th>
                    <th>Channel</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                    <tr class="border-b align-top">
                        <td class="max-w-xs py-2 pr-3">
                            <a class="font-medium text-indigo-600 hover:underline" href="{{ route('communication-templates.show', $template) }}">{{ $template->name }}</a>
                            <p class="mt-0.5 line-clamp-2 break-words text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($template->body, 120) }}</p>
                        </td>
                        <td class="py-2 pr-3"><span class="font-mono text-xs">{{ $template->code }}</span></td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'channel', 'value' => $template->channel, 'label' => $template->channelLabel()])</td>
                        <td class="max-w-xs py-2 pr-3 break-words">{{ $template->subject ?? '—' }}</td>
                        <td class="py-2 pr-3">@include('communication.partials.badge', ['kind' => 'template', 'value' => $template->status, 'label' => ucfirst($template->status)])</td>
                        <td class="whitespace-nowrap py-2 pr-3">{{ $template->updated_at?->format('d M Y, H:i') }}</td>
                        <td class="py-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                @can('update', $template)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.edit', $template) }}">Edit</a>
                                @endcan
                                @can('delete', $template)
                                    <form method="POST" action="{{ route('communication-templates.destroy', $template) }}" onsubmit="return confirm(@js('Delete the template "'.$template->name.'"? Existing logs keep their own copy of the message.'))">
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
                                No templates match these filters.
                            @else
                                No communication templates have been created in this college yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $templates->links() }}</div>
</div>
@endsection
