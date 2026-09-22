@extends('layouts.app')
@section('title', 'Transport Reports')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Transport Reports</h2>
            <p class="panel-subtitle">Read-only aggregates computed live from the existing Transport, Student and Finance records — there are no report tables and nothing on this screen can be edited.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('transport-reports.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
        <label class="text-sm font-medium text-slate-700">Report
            <select class="input mt-1" name="report">
                @foreach($reports as $key => $label)
                    <option value="{{ $key }}" @selected($report === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Academic year
            <select class="input mt-1" name="academic_year_id">
                <option value="">All</option>
                @foreach($years as $year)
                    <option value="{{ $year->id }}" @selected((string) $selected['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Route
            <select class="input mt-1" name="route_id">
                <option value="">All</option>
                @foreach($routes as $route)
                    <option value="{{ $route->id }}" @selected((string) $selected['route_id'] === (string) $route->id)>{{ $route->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Stop
            <select class="input mt-1" name="stop_id">
                <option value="">All</option>
                @foreach($stops as $stop)
                    <option value="{{ $stop->id }}" @selected((string) $selected['stop_id'] === (string) $stop->id)>{{ $routes->firstWhere('id', $stop->route_id)?->name ?? '—' }} — {{ $stop->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Vehicle
            <select class="input mt-1" name="vehicle_id">
                <option value="">All</option>
                @foreach($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected((string) $selected['vehicle_id'] === (string) $vehicle->id)>{{ $vehicle->registration_number }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Status
            <select class="input mt-1" name="status">
                <option value="">All</option>
                <optgroup label="Vehicle / assignment">
                    @foreach(['active', 'inactive', 'maintenance', 'retired', 'completed', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Document validity">
                    @foreach(['expiring', 'expired'] as $status)
                        <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </optgroup>
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">From
            <input class="input mt-1" type="date" name="from" value="{{ $selected['from'] }}">
        </label>
        <label class="text-sm font-medium text-slate-700">To
            <input class="input mt-1" type="date" name="to" value="{{ $selected['to'] }}">
        </label>
        <div class="flex items-end gap-2 xl:col-span-8">
            <button class="button" type="submit">Run report</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-reports.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        @if($report === 'vehicle_summary')
            <h3 class="panel-title mb-2">Vehicle Summary</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Registration</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Make / Model</th>
                    <th class="px-3 py-3">Seats</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Insurance expiry</th>
                    <th class="px-3 py-3">Documents</th><th class="px-3 py-3">Expiring soon</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($vehicleReport as $vehicle)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $vehicle->registration_number }}</td>
                            <td class="px-3 py-3">{{ $vehicle->vehicle_type ?? '—' }}</td>
                            <td class="px-3 py-3">{{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) !== '' ? trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) : '—' }}</td>
                            <td class="px-3 py-3">{{ $vehicle->seating_capacity ?? '—' }}</td>
                            <td class="px-3 py-3">{{ ucfirst($vehicle->status) }}</td>
                            <td class="px-3 py-3">{{ $vehicle->insurance_expiry?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $vehicle->documents_count }}</td>
                            <td class="px-3 py-3">{{ $vehicle->expiring_documents_count }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="8">No vehicles found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $vehicleReport->links() }}</div>

        @elseif($report === 'vehicle_status')
            <h3 class="panel-title mb-2">Vehicle Status</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr><th class="px-3 py-3">Status</th><th class="px-3 py-3">Vehicles</th></tr></thead>
                <tbody class="divide-y">
                    @forelse($statusReport as $row)
                        <tr><td class="px-3 py-3 font-medium">{{ ucfirst($row['status']) }}</td><td class="px-3 py-3">{{ $row['vehicles'] }}</td></tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="2">No vehicles found.</td></tr>
                    @endforelse
                </tbody>
            </table>

        @elseif($report === 'document_expiry')
            <h3 class="panel-title mb-2">Vehicle Document Expiry</h3>
            <p class="panel-subtitle mb-2">Nearest expiry first; expired documents are included because they need attention.</p>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Vehicle</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Document number</th>
                    <th class="px-3 py-3">Expiry</th><th class="px-3 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($documentReport as $doc)
                        @php($status = $doc->documentStatus())
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $doc->vehicle?->registration_number ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $doc->typeLabel() }}</td>
                            <td class="px-3 py-3">{{ $doc->document_number ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $doc->expiry_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                    @if($status === 'active') bg-emerald-100 text-emerald-700
                                    @elseif($status === 'expiring') bg-amber-100 text-amber-700
                                    @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($status) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="5">No documents with an expiry date found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $documentReport->links() }}</div>

        @elseif($report === 'driver_summary')
            <h3 class="panel-title mb-2">Driver Summary</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Staff</th><th class="px-3 py-3">License number</th><th class="px-3 py-3">License type</th>
                    <th class="px-3 py-3">License expiry</th><th class="px-3 py-3">Joining</th><th class="px-3 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($driverReport as $driver)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $driver->faculty?->full_name ?? 'Archived staff' }} <span class="block text-xs text-slate-500">{{ $driver->faculty?->employee_code ?? '—' }}</span></td>
                            <td class="px-3 py-3">{{ $driver->license_number }}</td>
                            <td class="px-3 py-3">{{ $driver->license_type }}</td>
                            <td class="px-3 py-3">{{ $driver->license_expiry?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $driver->joining_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-3">{{ ucfirst($driver->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No drivers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $driverReport->links() }}</div>

        @elseif($report === 'route_summary')
            <h3 class="panel-title mb-2">Route Summary</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Route</th><th class="px-3 py-3">Code</th><th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">Stops</th><th class="px-3 py-3">Active stops</th><th class="px-3 py-3">Active students</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($routeReport as $row)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $row['route']->name }}</td>
                            <td class="px-3 py-3">{{ $row['route']->code }}</td>
                            <td class="px-3 py-3">{{ ucfirst($row['route']->status) }}</td>
                            <td class="px-3 py-3">{{ $row['route']->stops_count }}</td>
                            <td class="px-3 py-3">{{ $row['route']->active_stops_count }}</td>
                            <td class="px-3 py-3">{{ $row['students'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No routes found.</td></tr>
                    @endforelse
                </tbody>
            </table>

        @elseif($report === 'stop_students')
            <h3 class="panel-title mb-2">Stop-wise Student Count</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Route</th><th class="px-3 py-3">Stop</th><th class="px-3 py-3">Code</th>
                    <th class="px-3 py-3">Sequence</th><th class="px-3 py-3">Active students</th><th class="px-3 py-3">Total assignments</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($stopReport as $row)
                        <tr>
                            <td class="px-3 py-3">{{ $row['stop']->route?->name ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium">{{ $row['stop']->name }}</td>
                            <td class="px-3 py-3">{{ $row['stop']->code }}</td>
                            <td class="px-3 py-3">{{ $row['stop']->sequence }}</td>
                            <td class="px-3 py-3">{{ $row['active_students'] }}</td>
                            <td class="px-3 py-3">{{ $row['total_assignments'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No stops found.</td></tr>
                    @endforelse
                </tbody>
            </table>

        @elseif($report === 'assignments')
            <h3 class="panel-title mb-2">Student Transport Assignment Report</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Student</th><th class="px-3 py-3">Enrollment</th><th class="px-3 py-3">Year</th>
                    <th class="px-3 py-3">Route</th><th class="px-3 py-3">Stop</th><th class="px-3 py-3">Period</th><th class="px-3 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($assignmentReport as $assignment)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $assignment->studentEnrollment?->student?->first_name }} {{ $assignment->studentEnrollment?->student?->last_name }} <span class="block text-xs text-slate-500">{{ $assignment->studentEnrollment?->student?->student_number }}</span></td>
                            <td class="px-3 py-3">{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $assignment->academicYear?->name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $assignment->transportRoute?->name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $assignment->transportStop?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ $assignment->start_date?->format('d M Y') }} {{ $assignment->end_date ? '→ '.$assignment->end_date->format('d M Y') : '' }}</td>
                            <td class="px-3 py-3">{{ ucfirst($assignment->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">No transport assignments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $assignmentReport->links() }}</div>

        @elseif($report === 'fee_summary' || $report === 'fee_outstanding')
            <h3 class="panel-title mb-2">{{ $report === 'fee_summary' ? 'Transport Fee Summary' : 'Transport Fee Outstanding Summary' }}</h3>
            <div class="mb-4 grid gap-4 sm:grid-cols-5">
                <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">Assigned</p><p class="mt-2 text-xl font-semibold">{{ number_format((float) $totals['assigned'], 2) }}</p></div>
                <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">Net collected</p><p class="mt-2 text-xl font-semibold">{{ number_format((float) $totals['net_collected'], 2) }}</p></div>
                <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">Outstanding</p><p class="mt-2 text-xl font-semibold">{{ number_format((float) $totals['outstanding'], 2) }}</p></div>
                <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">Assignments</p><p class="mt-2 text-xl font-semibold">{{ $totals['assignments'] }}</p></div>
                <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">With balance</p><p class="mt-2 text-xl font-semibold">{{ $totals['outstanding_assignments'] }}</p></div>
            </div>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Student</th><th class="px-3 py-3">Structure</th><th class="px-3 py-3">Period</th>
                    <th class="px-3 py-3">Amount</th><th class="px-3 py-3">Collected</th><th class="px-3 py-3">Outstanding</th><th class="px-3 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($rows as $feeAssignment)
                        @php($summary = $feeAssignment->ledger ?? null)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->first_name }} {{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->last_name }} <span class="block text-xs text-slate-500">{{ $feeAssignment->studentTransportAssignment?->studentEnrollment?->student?->student_number }}</span></td>
                            <td class="px-3 py-3">{{ $feeAssignment->transportFeeStructure?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ $feeAssignment->effective_from?->format('d M Y') }} {{ $feeAssignment->effective_until ? '→ '.$feeAssignment->effective_until->format('d M Y') : '' }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $feeAssignment->amount, 2) }}</td>
                            <td class="px-3 py-3">{{ number_format((float) ($summary['net_collected'] ?? 0), 2) }}</td>
                            <td class="px-3 py-3 font-semibold">{{ number_format((float) ($summary['outstanding'] ?? 0), 2) }}</td>
                            <td class="px-3 py-3">{{ ucfirst($feeAssignment->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="7">{{ $report === 'fee_outstanding' ? 'No transport fee assignments carry a balance.' : 'No transport fee assignments found.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $rows->links() }}</div>

        @elseif($report === 'active_inactive')
            <h3 class="panel-title mb-2">Active / Inactive Transport Assignments</h3>
            <div class="mb-4 grid gap-4 sm:grid-cols-3">
                @foreach($activeInactiveReport['counts'] as $status => $count)
                    <div class="rounded-lg border border-slate-200 p-4"><p class="text-sm text-slate-500">{{ ucfirst($status) }}</p><p class="mt-2 text-xl font-semibold">{{ $count }}</p></div>
                @endforeach
            </div>
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-slate-500"><tr>
                    <th class="px-3 py-3">Student</th><th class="px-3 py-3">Year</th><th class="px-3 py-3">Route</th>
                    <th class="px-3 py-3">Stop</th><th class="px-3 py-3">Period</th><th class="px-3 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y">
                    @forelse($activeInactiveReport['rows'] as $assignment)
                        <tr>
                            <td class="px-3 py-3 font-medium">{{ $assignment->studentEnrollment?->student?->first_name }} {{ $assignment->studentEnrollment?->student?->last_name }} <span class="block text-xs text-slate-500">{{ $assignment->studentEnrollment?->student?->student_number }}</span></td>
                            <td class="px-3 py-3">{{ $assignment->academicYear?->name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $assignment->transportRoute?->name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $assignment->transportStop?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ $assignment->start_date?->format('d M Y') }} {{ $assignment->end_date ? '→ '.$assignment->end_date->format('d M Y') : '' }}</td>
                            <td class="px-3 py-3">{{ ucfirst($assignment->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">No transport assignments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $activeInactiveReport['rows']->links() }}</div>
        @endif
    </div>
</div>
@endsection
