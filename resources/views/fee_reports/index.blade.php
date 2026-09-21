@extends('layouts.app')

@section('title', 'Fee Reports')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Reports</h2>
            <p class="panel-subtitle">
                Read-only reports aggregated live from the recorded financial transactions — no report stores its own copy of a
                financial fact, and cancelled collections are never counted as collected.
            </p>
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7" method="GET" action="{{ route('fee-reports.index') }}">
        <div>
            <label class="label" for="report">Report</label>
            <select class="input" id="report" name="report">
                @foreach($reports as $key => $label)
                    <option value="{{ $key }}" @selected($report === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="academic_year_id">Academic Year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((int) $selected['academic_year_id'] === $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="program_id">Program</label>
            <select class="input" id="program_id" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((int) $selected['program_id'] === $program->id)>{{ $program->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="student_id">Student</label>
            <select class="input" id="student_id" name="student_id">
                <option value="">All students</option>
                @foreach($students as $student)
                    <option value="{{ $student->id }}" @selected((int) $selected['student_id'] === $student->id)>{{ $student->student_number }} · {{ $student->fullName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="payment_mode">Payment Mode</label>
            <select class="input" id="payment_mode" name="payment_mode">
                <option value="">All modes</option>
                @foreach($usedPaymentModes as $mode)
                    <option value="{{ $mode }}" @selected($selected['payment_mode'] === $mode)>{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
                @endforeach
            </select>
        </div>
        {{--
            Date range — two native date controls are the widest pair in the
            filter bar. They live in a two-column grid of `minmax(0, 1fr)` tracks
            (Tailwind's `grid-cols-2`) and each control carries `min-w-0`, so a
            control shrinks with its track instead of pushing past the card; the
            cell spans two tracks of the filter grid so both dates stay readable
            and the row wraps at every breakpoint.
        --}}
        <div class="sm:col-span-2">
            <label class="label" for="from">Date from / to</label>
            <div class="grid grid-cols-2 gap-2">
                <input class="input min-w-0" id="from" name="from" type="date" aria-label="Date from" value="{{ $selected['from'] }}">
                <input class="input min-w-0" id="to" name="to" type="date" aria-label="Date to" value="{{ $selected['to'] }}">
            </div>
        </div>
        <div class="sm:col-span-2 lg:col-span-4 xl:col-span-7 flex flex-wrap items-end gap-2">
            @if(in_array($report, ['dues', 'student'], true))
                <div>
                    <label class="label" for="status">Due Status</label>
                    <select class="input" id="status" name="status">
                        <option value="">All</option>
                        @foreach(\App\Domain\Finance\Support\FeeLedger::STATUSES as $statusOption)
                            <option value="{{ $statusOption }}" @selected($selected['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <button class="button" type="submit">Run report</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-reports.index') }}">Reset</a>
        </div>
    </form>
</div>

@if(isset($collectionSummary))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Collection Summary</h3>
                <p class="panel-subtitle">Completed collections only. Cancelled/reversed payments are excluded.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Total Collected</p>
                <p class="mt-1 text-2xl font-bold">{{ number_format((float) $collectionSummary['total'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Payments Recorded</p>
                <p class="mt-1 text-2xl font-bold">{{ number_format($collectionSummary['payments']) }}</p>
            </div>
        </div>

        <div class="print-area mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Payment Mode</th>
                        <th class="text-right">Payments</th>
                        <th class="text-right">Total Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($collectionSummary['modes'] as $row)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ ucfirst(str_replace('_', ' ', $row['mode'])) }}</td>
                            <td class="text-right">{{ number_format($row['payments']) }}</td>
                            <td class="text-right">{{ number_format((float) $row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="3">No completed collections for this selection.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if(isset($dueSummary))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Due / Outstanding Summary</h3>
                <p class="panel-subtitle">Assigned fee − applicable concessions − valid collections + valid refunds.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Assignments</p>
                <p class="mt-1 text-lg font-bold">{{ number_format($dueSummary['assignments']) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Assigned</p>
                <p class="mt-1 text-lg font-bold">{{ number_format((float) $dueSummary['assigned'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Concessions</p>
                <p class="mt-1 text-lg font-bold">{{ number_format((float) $dueSummary['concession'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Collected (net)</p>
                <p class="mt-1 text-lg font-bold">{{ number_format((float) $dueSummary['net_collected'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Refunded</p>
                <p class="mt-1 text-lg font-bold">{{ number_format((float) $dueSummary['refunded'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Outstanding</p>
                <p class="mt-1 text-lg font-bold text-rose-700">{{ number_format((float) $dueSummary['outstanding'], 2) }}</p>
            </div>
        </div>
    </div>
@endif

@if(isset($studentReport))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Student Fee Report</h3>
                <p class="panel-subtitle">One row per fee assignment, with its live ledger position.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Student</th>
                        <th>Enrollment</th>
                        <th>Year / Program</th>
                        <th>Fee Structure</th>
                        <th class="text-right">Assigned</th>
                        <th class="text-right">Concession</th>
                        <th class="text-right">Collected</th>
                        <th class="text-right">Refunded</th>
                        <th class="text-right">Outstanding</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($studentReport as $assignment)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                            <td>{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                            <td>{{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $assignment->studentEnrollment?->program?->code ?? '—' }}</td>
                            <td>{{ $assignment->feeStructure?->name ?? '—' }}</td>
                            <td class="text-right">{{ number_format((float) $assignment->ledger['assigned'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $assignment->ledger['concession'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $assignment->ledger['net_collected'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $assignment->ledger['refunded'], 2) }}</td>
                            <td class="text-right font-semibold">{{ number_format((float) $assignment->ledger['outstanding'], 2) }}</td>
                            <td>{{ ucfirst($assignment->ledger['status']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="10">No fee assignments match this selection.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="no-print mt-6">{{ $studentReport->links() }}</div>
    </div>
@endif

@if(isset($programReport))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Program-wise Fee Report</h3>
                <p class="panel-subtitle">Ledger totals grouped by program.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Program</th>
                        <th class="text-right">Assignments</th>
                        <th class="text-right">Assigned</th>
                        <th class="text-right">Concessions</th>
                        <th class="text-right">Collected (net)</th>
                        <th class="text-right">Refunded</th>
                        <th class="text-right">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($programReport as $row)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ $row['program_name'] }}{{ $row['program_code'] ? ' ('.$row['program_code'].')' : '' }}</td>
                            <td class="text-right">{{ number_format($row['assignments']) }}</td>
                            <td class="text-right">{{ number_format((float) $row['assigned'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $row['concession'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $row['net_collected'], 2) }}</td>
                            <td class="text-right">{{ number_format((float) $row['refunded'], 2) }}</td>
                            <td class="text-right font-semibold">{{ number_format((float) $row['outstanding'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="7">No fee assignments match this selection.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if(isset($modeReport))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Payment Mode Report</h3>
                <p class="panel-subtitle">Completed collections grouped by payment mode, largest first.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Payment Mode</th>
                        <th class="text-right">Payments</th>
                        <th class="text-right">Total Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($modeReport as $row)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ ucfirst(str_replace('_', ' ', $row['mode'])) }}</td>
                            <td class="text-right">{{ number_format($row['payments']) }}</td>
                            <td class="text-right">{{ number_format((float) $row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="3">No completed collections for this selection.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if(isset($dateReport))
    <div class="panel mt-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold">Date-wise Collection Report</h3>
                <p class="panel-subtitle">Completed collections grouped by payment date, most recent first.</p>
            </div>
            <button class="no-print button !bg-slate-200 !text-slate-700" type="button" onclick="window.print()">Print</button>
        </div>

        <div class="print-area mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Payment Date</th>
                        <th class="text-right">Payments</th>
                        <th class="text-right">Total Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dateReport as $row)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ $row['date'] }}</td>
                            <td class="text-right">{{ number_format($row['payments']) }}</td>
                            <td class="text-right">{{ number_format((float) $row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="3">No completed collections for this selection.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
