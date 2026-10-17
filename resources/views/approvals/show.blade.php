@extends('layouts.app')
@section('title','Approval Request')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex items-center justify-between">
            <h2 class="panel-title">Approval Request {{ $request->reference_number }}</h2>
            <span class="rounded-full px-3 py-1 text-xs font-semibold
                @if($request->status==='pending') bg-yellow-100 text-yellow-700 @elseif($request->status==='approved') bg-green-100 text-green-700 @elseif($request->status==='rejected') bg-red-100 text-red-700 @else bg-slate-100 text-slate-600 @endif">{{ ucfirst($request->status) }}</span>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 text-sm">
            <div><span class="text-slate-500">Module / Action:</span> <span class="font-medium">{{ ucfirst($request->module) }} / {{ $request->action }}</span></div>
            <div><span class="text-slate-500">Reference:</span> <span class="font-medium">{{ $request->reference_number }}</span></div>
            <div><span class="text-slate-500">Requested By:</span> <span class="font-medium">{{ $request->requester?->name ?? '—' }}</span></div>
            <div><span class="text-slate-500">Created:</span> <span class="font-medium">{{ $request->created_at->format('Y-m-d H:i') }}</span></div>
            <div><span class="text-slate-500">Target:</span> <span class="font-medium">{{ $request->target_type }} #{{ $request->target_id }}</span></div>
            <div><span class="text-slate-500">College:</span> <span class="font-medium">{{ $request->college?->name ?? $request->college_id }}</span></div>
            @if($request->payload)<div class="sm:col-span-2"><span class="text-slate-500">Proposed Changes:</span><pre class="mt-1 rounded bg-slate-50 p-3 text-xs overflow-x-auto">{{ json_encode($request->payload, JSON_PRETTY_PRINT) }}</pre></div>@endif
            @if($request->previous_data)<div class="sm:col-span-2"><span class="text-slate-500">Previous Approved Value:</span><pre class="mt-1 rounded bg-slate-50 p-3 text-xs overflow-x-auto">{{ json_encode($request->previous_data, JSON_PRETTY_PRINT) }}</pre></div>@endif
            @if($request->request_reason)<div class="sm:col-span-2"><span class="text-slate-500">Request Reason:</span><p class="mt-1 rounded bg-slate-50 p-3">{{ $request->request_reason }}</p></div>@endif
            @if($request->decided_by)<div><span class="text-slate-500">Decided By:</span> <span class="font-medium">{{ $request->decider?->name ?? '—' }}</span></div><div><span class="text-slate-500">Decided At:</span> <span class="font-medium">{{ $request->decided_at?->format('Y-m-d H:i') }}</span></div>@endif
            @if($request->decision_reason)<div class="sm:col-span-2"><span class="text-slate-500">Decision Reason:</span><p class="mt-1 rounded bg-slate-50 p-3">{{ $request->decision_reason }}</p></div>@endif
        </div>

        @if($request->status === 'pending')
            <div class="mt-6 border-t pt-6">
                <h3 class="font-semibold">Take Action</h3>
                <div class="mt-4 grid gap-6 lg:grid-cols-2">
                    <form method="POST" action="{{ route('approvals.approve', $request) }}" class="rounded-lg border border-green-200 bg-green-50 p-4">
                        @csrf
                        <label class="text-sm font-medium text-green-800">Approve</label>
                        <textarea name="decision_reason" class="input mt-2 w-full" rows="3" placeholder="Optional approval note..."></textarea>
                        <button type="submit" class="button mt-3 !bg-green-600">Approve Request</button>
                    </form>
                    <form method="POST" action="{{ route('approvals.reject', $request) }}" class="rounded-lg border border-red-200 bg-red-50 p-4">
                        @csrf
                        <label class="text-sm font-medium text-red-800">Reject <span class="text-xs font-normal">(reason required)</span></label>
                        <textarea name="decision_reason" class="input mt-2 w-full" rows="3" placeholder="Reason for rejection..." required></textarea>
                        <button type="submit" class="button mt-3 !bg-red-600">Reject Request</button>
                    </form>
                </div>
                @can('cancel', $request)
                    <form method="POST" action="{{ route('approvals.cancel', $request) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="button !bg-slate-200 !text-slate-700 text-sm">Cancel Request</button>
                    </form>
                @endcan
            </div>
        @endif
    </div>

    <div class="panel">
        <a href="{{ route('approvals.pending') }}" class="text-sm text-indigo-600 hover:underline">← Back to Pending</a>
        <span class="mx-2 text-slate-300">|</span>
        <a href="{{ route('approvals.my-requests') }}" class="text-sm text-indigo-600 hover:underline">My Requests</a>
        <span class="mx-2 text-slate-300">|</span>
        <a href="{{ route('approvals.history') }}" class="text-sm text-indigo-600 hover:underline">History</a>
    </div>
</div>
@endsection
