@extends('layouts.app')
@section('title','Approval Settings')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Approval Settings</h2>
        <p class="panel-subtitle">Configure which supported modules and actions require approval. Only authorized administrators can change these settings. Existing pending requests remain valid when approval is disabled.</p>
    </div>

    <form method="POST" action="{{ route('approvals.settings.update') }}">
        @csrf
        @method('PUT')
        <div class="panel">
            <h3 class="font-semibold">Supported Actions</h3>
            <p class="mt-1 text-xs text-slate-500">Toggle whether each action requires approval. The allowlist is controlled server-side; unsupported actions cannot be configured.</p>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Module</th><th>Action</th><th>Label</th><th class="text-center">Requires Approval</th></tr></thead>
                    <tbody>
                    @foreach($supported as $idx => $item)
                        @php
                            $key = $item['module'].'.'.$item['action'];
                            $existing = $settingsMap[$key] ?? null;
                            $isRequired = $existing ? (bool) $existing->is_required : ($item['module']==='admission');
                        @endphp
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ ucfirst($item['module']) }}</td>
                            <td class="py-2 font-mono text-xs">{{ $item['action'] }}</td>
                            <td class="py-2">{{ $item['label'] }}</td>
                            <td class="py-2 text-center">
                                <input type="hidden" name="settings[{{ $idx }}][module]" value="{{ $item['module'] }}">
                                <input type="hidden" name="settings[{{ $idx }}][action]" value="{{ $item['action'] }}">
                                <label class="inline-flex items-center gap-2">
                                    <input type="hidden" name="settings[{{ $idx }}][is_required]" value="0">
                                    <input type="checkbox" name="settings[{{ $idx }}][is_required]" value="1" @checked($isRequired) class="rounded border-slate-300">
                                    <span class="text-xs">{{ $isRequired ? 'Enabled' : 'Disabled' }}</span>
                                </label>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit" class="button">Save Settings</button>
                <a href="{{ route('approvals.pending') }}" class="button !bg-slate-200 !text-slate-700">Back to Approvals</a>
            </div>
        </div>
    </form>

    <div class="panel bg-amber-50 border-amber-200">
        <h4 class="text-sm font-semibold text-amber-800">Audit Trail</h4>
        <p class="mt-1 text-xs text-amber-700">Configuration changes are recorded in the audit log with before/after values. Disabling approval does not retroactively invalidate existing pending requests — they remain pending and decidable until acted upon.</p>
    </div>
</div>
@endsection
