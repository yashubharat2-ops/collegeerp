@extends('layouts.app')
@section('title','Approval History')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Approval History</h2>
        <p class="panel-subtitle">Authorized approval decisions including approved and rejected requests.</p>
        <form method="GET" action="{{ route('approvals.history') }}" class="mt-4 flex gap-3 flex-wrap">
            <select name="status" class="input text-sm"><option value="">All statuses</option><option value="approved" @selected($status==='approved')>Approved</option><option value="rejected" @selected($status==='rejected')>Rejected</option></select>
            <select name="module" class="input text-sm"><option value="">All modules</option>@foreach($modules as $mod)<option value="{{ $mod }}" @selected($module===$mod)>{{ ucfirst($mod) }}</option>@endforeach</select>
            <button class="button text-sm" type="submit">Filter</button>
            @if($status || $module)<a class="button !bg-slate-200 !text-slate-700 text-sm" href="{{ route('approvals.history') }}">Clear</a>@endif
        </form>
    </div>
    <div class="panel">
        @if($requests->count()===0)
            <p class="py-8 text-center text-sm text-slate-500">No approval history found.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Reference</th><th>Module / Action</th><th>Requested By</th><th>Decided By</th><th>Status</th><th>Decided At</th><th>Reason</th></tr></thead>
                <tbody>
                @foreach($requests as $req)
                    <tr class="border-b">
                        <td class="py-2 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('approvals.show', $req) }}">{{ $req->reference_number }}</a></td>
                        <td class="py-2">{{ ucfirst($req->module) }} / {{ $req->action }}</td>
                        <td class="py-2">{{ $req->requester?->name ?? '—' }}</td>
                        <td class="py-2">{{ $req->decider?->name ?? '—' }}</td>
                        <td class="py-2"><span class="rounded-full px-2 py-1 text-xs font-semibold @if($req->status==='approved') bg-green-100 text-green-700 @else bg-red-100 text-red-700 @endif">{{ ucfirst($req->status) }}</span></td>
                        <td class="py-2">{{ $req->decided_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="py-2 max-w-xs truncate">{{ $req->decision_reason ?? '—' }}</td>
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
