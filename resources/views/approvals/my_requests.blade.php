@extends('layouts.app')
@section('title','My Requests')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">My Requests</h2>
        <p class="panel-subtitle">Requests submitted by you and their current status.</p>
        <form method="GET" action="{{ route('approvals.my-requests') }}" class="mt-4 flex gap-3">
            <select name="status" class="input text-sm"><option value="">All statuses</option>@foreach(['pending','approved','rejected','cancelled'] as $st)<option value="{{ $st }}" @selected($status===$st)>{{ ucfirst($st) }}</option>@endforeach</select>
            <button class="button text-sm" type="submit">Filter</button>
            @if($status)<a class="button !bg-slate-200 !text-slate-700 text-sm" href="{{ route('approvals.my-requests') }}">Clear</a>@endif
        </form>
    </div>
    <div class="panel">
        @if($requests->count()===0)
            <p class="py-8 text-center text-sm text-slate-500">You have not submitted any approval requests.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Reference</th><th>Module / Action</th><th>Target</th><th>Status</th><th>Created</th><th>Decision</th></tr></thead>
                <tbody>
                @foreach($requests as $req)
                    <tr class="border-b">
                        <td class="py-2 font-medium"><a class="text-indigo-600 hover:underline" href="{{ route('approvals.show', $req) }}">{{ $req->reference_number }}</a></td>
                        <td class="py-2">{{ ucfirst($req->module) }} / {{ $req->action }}</td>
                        <td class="py-2">{{ $req->target_type }} #{{ $req->target_id }}</td>
                        <td class="py-2"><span class="rounded-full px-2 py-1 text-xs font-semibold
                            @if($req->status==='pending') bg-yellow-100 text-yellow-700 @elseif($req->status==='approved') bg-green-100 text-green-700 @elseif($req->status==='rejected') bg-red-100 text-red-700 @else bg-slate-100 text-slate-600 @endif">{{ ucfirst($req->status) }}</span></td>
                        <td class="py-2">{{ $req->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2">{{ $req->decision_reason ?? ($req->decided_at?->format('Y-m-d H:i') ?? '—') }}</td>
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
