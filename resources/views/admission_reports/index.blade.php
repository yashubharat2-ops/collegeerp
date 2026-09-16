@extends('layouts.app')
@section('title','Admission Reports')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Admission Reports</h2>
        <p class="panel-subtitle">Tenant-scoped filters for application status, program-wise, document verification, merit/selection, admitted students and academic-year summary.</p>

        <form method="GET" action="{{ route('admission-reports.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <select class="input" name="academic_year_id">
                <option value="">All academic years</option>
                @foreach($academicYears as $ay)
                    <option value="{{ $ay->id }}" @selected((string)$academic_year_id === (string)$ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                @endforeach
            </select>
            <select class="input" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $prog)
                    <option value="{{ $prog->id }}" @selected((string)$program_id === (string)$prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                @endforeach
            </select>
            <select class="input" name="status">
                <option value="">All application statuses</option>
                <option value="draft" @selected($status === 'draft')>Draft</option>
                <option value="submitted" @selected($status === 'submitted')>Submitted</option>
                <option value="under_review" @selected($status === 'under_review')>Under Review</option>
                <option value="approved" @selected($status === 'approved')>Approved</option>
                <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                <option value="admitted" @selected($status === 'admitted')>Admitted</option>
                <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
            </select>
            <div class="flex gap-2">
                <button class="button" type="submit">Filter</button>
                @if($academic_year_id || $program_id || $status)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-reports.index') }}">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="font-semibold">Application Status Summary</h3>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse($statusCounts as $st => $cnt)
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst(str_replace('_',' ',$st)) }}: {{ $cnt }}</span>
                @empty
                    <span class="text-xs text-slate-500">No applications</span>
                @endforelse
            </div>
        </div>
        <div class="panel">
            <h3 class="font-semibold">Document Verification Status</h3>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse($docStatusCounts as $st => $cnt)
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                @empty
                    <span class="text-xs text-slate-500">No documents</span>
                @endforelse
            </div>
        </div>
        <div class="panel">
            <h3 class="font-semibold">Merit / Selection Status</h3>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse($selectionCounts as $st => $cnt)
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                @empty
                    <span class="text-xs text-slate-500">No merit entries</span>
                @endforelse
            </div>
        </div>
        <div class="panel">
            <h3 class="font-semibold">Admissions Status</h3>
            <div class="mt-4 flex flex-wrap gap-2">
                @forelse($admissionStatusCounts as $st => $cnt)
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                @empty
                    <span class="text-xs text-slate-500">No admissions</span>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="font-semibold">Program-wise Applications</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th>Program</th><th>Total</th><th>Approved</th><th>Admitted</th></tr></thead>
                    <tbody>
                    @forelse($programWise as $row)
                        <tr class="border-b"><td class="py-2">{{ $row->program?->name ?? $row->program_id }}</td><td>{{ $row->total }}</td><td>{{ $row->approved }}</td><td>{{ $row->admitted }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="panel">
            <h3 class="font-semibold">Academic Year Summary</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th>Year</th><th>Total</th><th>Approved</th><th>Admitted</th></tr></thead>
                    <tbody>
                    @forelse($yearWise as $row)
                        <tr class="border-b"><td class="py-2">{{ $row->academicYear?->name ?? $row->academic_year_id }}</td><td>{{ $row->total }}</td><td>{{ $row->approved }}</td><td>{{ $row->admitted }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Applications (filtered)</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th>Application No</th><th>Applicant</th><th>Program</th><th>Year</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($applications as $app)
                    <tr class="border-b"><td class="py-2 font-medium">{{ $app->application_number }}</td><td>{{ $app->applicant->first_name }} {{ $app->applicant->last_name }}</td><td>{{ $app->program?->name ?? '—' }}</td><td>{{ $app->academicYear?->name ?? '—' }}</td><td>{{ ucfirst(str_replace('_',' ',$app->status)) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-500">No applications</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $applications->links() }}</div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Admitted Students / Applications</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th>Admission No</th><th>Applicant</th><th>Application</th><th>Program</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($admissions as $adm)
                    <tr class="border-b"><td class="py-2 font-medium">{{ $adm->admission_number }}</td><td>{{ $adm->applicant->first_name }} {{ $adm->applicant->last_name }}</td><td>{{ $adm->application->application_number }}</td><td>{{ $adm->program?->name ?? '—' }}</td><td>{{ $adm->admission_date?->format('Y-m-d') }}</td><td>{{ ucfirst($adm->status) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-500">No admissions</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $admissions->links() }}</div>
    </div>
</div>
@endsection
