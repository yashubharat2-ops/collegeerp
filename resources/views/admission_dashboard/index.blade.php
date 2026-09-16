@extends('layouts.app')
@section('title','Admission Dashboard')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Admission Dashboard</h2>
        <p class="panel-subtitle">Tenant-scoped overview of enquiries, applicants, applications, documents and admissions.</p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Enquiries</p>
                <p class="mt-2 text-2xl font-bold">{{ $totalEnquiries }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Applicants</p>
                <p class="mt-2 text-2xl font-bold">{{ $totalApplicants }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Applications</p>
                <p class="mt-2 text-2xl font-bold">{{ $totalApplications }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Admissions</p>
                <p class="mt-2 text-2xl font-bold">{{ $totalAdmissions }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Draft</p><p class="text-xl font-bold">{{ $draft }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Submitted</p><p class="text-xl font-bold">{{ $submitted }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Under Review</p><p class="text-xl font-bold">{{ $underReview }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Approved</p><p class="text-xl font-bold">{{ $approved }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Rejected</p><p class="text-xl font-bold">{{ $rejected }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Admitted</p><p class="text-xl font-bold">{{ $admitted }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Cancelled</p><p class="text-xl font-bold">{{ $cancelled }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Documents Pending</p><p class="text-xl font-bold">{{ $pendingDocs }} / {{ $totalDocs }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Verified Docs</p><p class="text-xl font-bold">{{ $verifiedDocs }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Rejected Docs</p><p class="text-xl font-bold">{{ $rejectedDocs }}</p></div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="font-semibold">Program-wise Applications</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Program</th><th>Count</th></tr></thead>
                    <tbody>
                    @forelse($programWise as $row)
                        <tr class="border-b"><td class="py-2">{{ $row->program?->name ?? '—' }} ({{ $row->program?->code ?? $row->program_id }})</td><td>{{ $row->count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="py-4 text-slate-500">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="panel">
            <h3 class="font-semibold">Academic Year-wise Applications</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Academic Year</th><th>Count</th></tr></thead>
                    <tbody>
                    @forelse($yearWise as $row)
                        <tr class="border-b"><td class="py-2">{{ $row->academicYear?->name ?? '—' }} ({{ $row->academicYear?->code ?? $row->academic_year_id }})</td><td>{{ $row->count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="py-4 text-slate-500">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Status Breakdown</h3>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach($statusBreakdown as $status => $count)
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst(str_replace('_',' ',$status)) }}: {{ $count }}</span>
            @endforeach
        </div>
    </div>
</div>
@endsection
