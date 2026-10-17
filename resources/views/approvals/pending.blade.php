@extends('layouts.app')
@section('title','Pending Approvals')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Pending Approvals</h2>
        <p class="panel-subtitle">Requests awaiting your approval based on your authorized role and permissions.</p>
    </div>

    <div class="panel">
        @if($requests->count() === 0)
            <div class="py-12 text-center">
                <p class="text-sm text-slate-500">No pending approvals awaiting your action.</p>
                <p class="mt-2 text-xs text-slate-400">When colleagues submit requests for actions you are authorized to approve, they will appear here.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Reference</th><th>Module / Action</th><th>Requested By</th><th>Target</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach($requests as $req)
                        <tr class="border-b">
                            <td class="py-2 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('approvals.show', $req) }}">{{ $req->reference_number }}</a></td>
                            <td class="py-2">{{ ucfirst($req->module) }} / {{ $req->action }}</td>
                            <td class="py-2">{{ $req->requester?->name ?? '—' }}</td>
                            <td class="py-2">{{ $req->target_type }} #{{ $req->target_id }}</td>
                            <td class="py-2">{{ $req->created_at->format('Y-m-d H:i') }}</td>
                            <td class="py-2">
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('approvals.approve', $req) }}">@csrf<button class="button text-xs" type="submit">Approve</button></form>
                                    <a class="button !bg-slate-200 !text-slate-700 text-xs" href="{{ route('approvals.show', $req) }}">Review</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
@endsection
