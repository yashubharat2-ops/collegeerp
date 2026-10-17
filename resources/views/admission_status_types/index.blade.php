@extends('layouts.app')
@section('title','Admission Status Types')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Admission Status Types</h2>
        <p class="panel-subtitle">Configured workflow statuses for admission applications and admission records. These are the valid transitions enforced server-side.</p>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Application Statuses</h3>
        <p class="mt-1 text-xs text-slate-500">Defined in AdmissionApplicationWorkflow — institution-agnostic lifecycle. Invalid jumps such as draft → approved are rejected.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach($applicationStatuses as $status)
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst(str_replace('_',' ',$status)) }}</span>
            @endforeach
        </div>

        <h4 class="mt-6 font-medium">Allowed Transitions</h4>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">From</th><th>Allowed Next Statuses</th></tr></thead>
                <tbody>
                @foreach($transitions as $from => $allowed)
                    <tr class="border-b"><td class="py-2 font-medium">{{ ucfirst(str_replace('_',' ',$from)) }}</td><td class="py-2">{{ implode(', ', array_map(fn($s)=>ucfirst(str_replace('_',' ',$s)), $allowed)) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Admission Record Statuses</h3>
        <p class="mt-1 text-xs text-slate-500">Final enrollment records derived from approved applications.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach($admissionStatuses as $status)
                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">{{ ucfirst($status) }}</span>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-slate-500">Active = confirmed admission, Cancelled = revoked (preserves history), Completed = program finished.</p>
    </div>
</div>
@endsection
