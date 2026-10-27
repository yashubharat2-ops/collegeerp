@extends('layouts.app')
@section('title','Admission Reports')
@section('content')
<div class="space-y-6">
    <div class="panel">
        <h2 class="panel-title">Admission Reports</h2>
        <p class="panel-subtitle">Tenant-scoped admission insights with academic year, program, status and date-range filters. All data is live from existing records.</p>

        <form method="GET" action="{{ route('admission-reports.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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
                <option value="">All statuses</option>
                <option value="draft" @selected($status === 'draft')>Draft</option>
                <option value="submitted" @selected($status === 'submitted')>Submitted</option>
                <option value="under_review" @selected($status === 'under_review')>Under Review</option>
                <option value="approved" @selected($status === 'approved')>Approved</option>
                <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                <option value="admitted" @selected($status === 'admitted')>Admitted</option>
                <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
            </select>
            <input type="date" class="input" name="date_from" value="{{ $date_from }}" placeholder="From date">
            <input type="date" class="input" name="date_to" value="{{ $date_to }}" placeholder="To date">
            <div class="flex gap-2">
                <button class="button" type="submit">Filter</button>
                @if($academic_year_id || $program_id || $status || $date_from || $date_to)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-reports.index') }}">Clear</a>
                @endif
            </div>
        </form>
        @error('date_from')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('date_to')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- Report Navigation Anchors --}}
    <div class="panel">
        <h3 class="text-sm font-semibold text-slate-700">Reports</h3>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="#admission-summary" class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">Admission Summary</a>
            <a href="#application-report" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold hover:bg-slate-200">Application Report</a>
            <a href="#new-registration-report" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold hover:bg-slate-200">New Registration Report</a>
            <a href="#student-admission-report" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold hover:bg-slate-200">Student Admission Report</a>
            <a href="#admission-status-report" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold hover:bg-slate-200">Admission Status Report</a>
        </div>
    </div>

    {{-- 1. Admission Summary --}}
    <div id="admission-summary" class="panel">
        <h3 class="font-semibold text-lg">1. Admission Summary</h3>
        <p class="text-xs text-slate-500">Overview totals for the filtered scope. Uses actual database records with tenant isolation.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-lg border bg-white p-4"><p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Applicants</p><p class="mt-2 text-2xl font-bold">{{ $summary['total_applicants'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Applications</p><p class="mt-2 text-2xl font-bold">{{ $summary['total_applications'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Total Admissions</p><p class="mt-2 text-2xl font-bold">{{ $summary['total_admissions'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Approved Applications</p><p class="text-xl font-bold text-green-600">{{ $summary['approved_applications'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Pending (Submitted/Under Review)</p><p class="text-xl font-bold text-amber-600">{{ $summary['pending_applications'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Rejected Applications</p><p class="text-xl font-bold text-red-600">{{ $summary['rejected_applications'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Pending Registrations</p><p class="text-xl font-bold text-amber-600">{{ $summary['pending_registrations'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Approved Registrations</p><p class="text-xl font-bold text-green-600">{{ $summary['approved_registrations'] }}</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Rejected Registrations</p><p class="text-xl font-bold text-red-600">{{ $summary['rejected_registrations'] }}</p></div>
        </div>

        @if($summary['total_applications'] === 0)
            <div class="mt-6 rounded-lg border border-dashed bg-slate-50 p-6 text-center"><p class="text-sm text-slate-500">No applications found for the selected filters.</p><p class="mt-1 text-xs text-slate-400">Try adjusting the academic year, program, status or date range.</p></div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border bg-slate-50 p-4">
                <h4 class="text-sm font-semibold">Application Status Summary</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($statusCounts as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst(str_replace('_',' ',$st)) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No applications</span>
                    @endforelse
                </div>
            </div>
            <div class="rounded-lg border bg-slate-50 p-4">
                <h4 class="text-sm font-semibold">Admissions Status</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($admissionStatusCounts as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No admissions</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- 2. Application Report --}}
    <div id="application-report" class="panel">
        <h3 class="font-semibold text-lg">2. Application Report</h3>
        <p class="text-xs text-slate-500">Paginated list of admission applications. Supports academic year, program, status and date-range filters with tenant scoping.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Application No</th><th>Applicant</th><th>Program</th><th>Year</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                @forelse($applications as $app)
                    <tr class="border-b"><td class="py-2 font-medium">{{ $app->application_number }}</td><td>{{ $app->applicant->first_name }} {{ $app->applicant->last_name }}</td><td>{{ $app->program?->name ?? '—' }}</td><td>{{ $app->academicYear?->name ?? '—' }}</td><td><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ ucfirst(str_replace('_',' ',$app->status)) }}</span></td><td>{{ $app->created_at?->format('Y-m-d') }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No applications match the selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $applications->appends(request()->query())->links() }}</div>
    </div>

    {{-- 3. New Registration Report --}}
    <div id="new-registration-report" class="panel">
        <h3 class="font-semibold text-lg">3. New Registration Report</h3>
        <p class="text-xs text-slate-500">New applicant registrations in the selected date range. Tenant-scoped with meaningful totals.</p>
        <div class="mt-3">
            <p class="text-sm">Total New Registrations: <span class="font-bold">{{ $newRegistrationTotal }}</span> <span class="text-xs text-slate-500">(applicants created in filtered period)</span></p>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Applicant</th><th>Email</th><th>Phone</th><th>Status</th><th>Applications</th><th>Registered On</th></tr></thead>
                <tbody>
                @forelse($newRegistrations as $reg)
                    <tr class="border-b"><td class="py-2 font-medium">{{ $reg->first_name }} {{ $reg->last_name }}</td><td>{{ $reg->email ?? '—' }}</td><td>{{ $reg->phone ?? '—' }}</td><td>{{ ucfirst($reg->status) }}</td><td>{{ $reg->applications_count }}</td><td>{{ $reg->created_at?->format('Y-m-d') }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No new registrations in the selected period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $newRegistrations->appends(request()->query())->links() }}</div>
    </div>

    {{-- 4. Student Admission Report --}}
    <div id="student-admission-report" class="panel">
        <h3 class="font-semibold text-lg">4. Student Admission Report</h3>
        <p class="text-xs text-slate-500">Confirmed admissions (active enrollment records) with program/year/date filters and pagination.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Admission No</th><th>Applicant</th><th>Application</th><th>Program</th><th>Year</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($admissions as $adm)
                    <tr class="border-b"><td class="py-2 font-medium">{{ $adm->admission_number }}</td><td>{{ $adm->applicant->first_name }} {{ $adm->applicant->last_name }}</td><td>{{ $adm->application->application_number }}</td><td>{{ $adm->program?->name ?? '—' }}</td><td>{{ $adm->academicYear?->name ?? '—' }}</td><td>{{ $adm->admission_date?->format('Y-m-d') }}</td><td><span class="rounded-full px-2 py-1 text-xs @if($adm->status==='active') bg-green-100 text-green-700 @elseif($adm->status==='cancelled') bg-red-100 text-red-700 @else bg-slate-100 @endif">{{ ucfirst($adm->status) }}</span></td></tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-slate-500">No admissions found for the selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $admissions->appends(request()->query())->links() }}</div>
    </div>

    {{-- 5. Admission Status Report --}}
    <div id="admission-status-report" class="panel">
        <h3 class="font-semibold text-lg">5. Admission Status Report</h3>
        <p class="text-xs text-slate-500">Detailed breakdown by application status, admission status, program and academic year.</p>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border p-4">
                <h4 class="text-sm font-semibold">Program-wise Applications</h4>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-slate-500"><th>Program</th><th>Total</th><th>Approved</th><th>Admitted</th><th>Rejected</th></tr></thead>
                        <tbody>
                        @forelse($programWise as $row)
                            <tr class="border-b"><td class="py-2">{{ $row->program?->name ?? $row->program_id }}</td><td>{{ $row->total }}</td><td class="text-green-600">{{ $row->approved }}</td><td class="text-indigo-600">{{ $row->admitted }}</td><td class="text-red-600">{{ $row->rejected }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-slate-500">No program data</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <h4 class="text-sm font-semibold">Academic Year Summary</h4>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-slate-500"><th>Year</th><th>Total</th><th>Approved</th><th>Admitted</th><th>Rejected</th></tr></thead>
                        <tbody>
                        @forelse($yearWise as $row)
                            <tr class="border-b"><td class="py-2">{{ $row->academicYear?->name ?? $row->academic_year_id }}</td><td>{{ $row->total }}</td><td class="text-green-600">{{ $row->approved }}</td><td class="text-indigo-600">{{ $row->admitted }}</td><td class="text-red-600">{{ $row->rejected }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-slate-500">No year data</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="rounded-lg border bg-slate-50 p-4">
                <h4 class="text-sm font-semibold">Application Status Breakdown</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($admissionStatusReport['application_status_breakdown'] as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst(str_replace('_',' ',$st)) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No data</span>
                    @endforelse
                </div>
            </div>
            <div class="rounded-lg border bg-slate-50 p-4">
                <h4 class="text-sm font-semibold">Admission Status Breakdown</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($admissionStatusReport['admission_status_breakdown'] as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No data</span>
                    @endforelse
                </div>
            </div>
            <div class="rounded-lg border bg-slate-50 p-4">
                <h4 class="text-sm font-semibold">Document Verification</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($docStatusCounts as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No documents</span>
                    @endforelse
                </div>
                <h4 class="mt-4 text-sm font-semibold">Selection Status</h4>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($selectionCounts as $st => $cnt)
                        <span class="rounded-full bg-white border px-3 py-1 text-xs font-semibold">{{ ucfirst($st) }}: {{ $cnt }}</span>
                    @empty
                        <span class="text-xs text-slate-500">No merit entries</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
