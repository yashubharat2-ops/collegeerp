@extends('layouts.app')

@section('title', 'Due / Outstanding Fees')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Due / Outstanding Fees</h2>
            <p class="panel-subtitle">
                Read-only ledger. Outstanding = assigned fee + valid refunds − valid concessions − valid collections.
                Cancelled payments are excluded and nothing on this screen can be edited.
            </p>
        </div>
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6" method="GET" action="{{ route('fee-dues.index') }}">
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
            <label class="label" for="fee_structure_id">Fee Structure</label>
            <select class="input" id="fee_structure_id" name="fee_structure_id">
                <option value="">All structures</option>
                @foreach($feeStructures as $structure)
                    <option value="{{ $structure->id }}" @selected((int) $selected['fee_structure_id'] === $structure->id)>{{ $structure->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Due Status</label>
            <select class="input" id="status" name="status">
                <option value="">All</option>
                @foreach($ledgerStatuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($selected['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="student_enrollment_id">Enrollment</label>
            <select class="input" id="student_enrollment_id" name="student_enrollment_id">
                <option value="">All enrollments</option>
                @foreach($enrollments as $enrollment)
                    <option value="{{ $enrollment->id }}" @selected((int) $selected['student_enrollment_id'] === $enrollment->id)>{{ $enrollment->enrollment_number }} · {{ $enrollment->student?->fullName() ?? '—' }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3 lg:col-span-6">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-dues.index') }}">Reset</a>
        </div>
    </form>

    <div class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Assignments</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['assignments']) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Assigned</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['assigned'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Concessions</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['concession'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Collected (net)</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['net_collected'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Refunded</p>
            <p class="mt-1 text-lg font-bold">{{ number_format($totals['refunded'], 2) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-widest text-slate-500">Outstanding</p>
            <p class="mt-1 text-lg font-bold text-rose-700">{{ number_format($totals['outstanding'], 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Student</th>
                    <th>Enrollment</th>
                    <th>Year / Program</th>
                    <th>Fee Structure</th>
                    <th class="text-right">Assigned</th>
                    <th class="text-right">Concession</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Refunded</th>
                    <th class="text-right">Outstanding</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dues as $assignment)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                        <td>{{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $assignment->studentEnrollment?->program?->code ?? '—' }}</td>
                        <td>{{ $assignment->feeStructure?->name ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['assigned'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['concession'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['paid'], 2) }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->ledger['refunded'], 2) }}</td>
                        <td class="text-right font-semibold">{{ number_format((float) $assignment->ledger['outstanding'], 2) }}</td>
                        <td>
                            @php($status = $assignment->ledger['status'])
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $status === 'paid' ? 'bg-emerald-100 text-emerald-700' : ($status === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') }}">{{ ucfirst($status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('create', App\Models\FeePayment::class)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.create', ['student_fee_assignment_id' => $assignment->id]) }}">Collect</a>
                                @endcan
                                @can('create', App\Models\FeeConcession::class)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.create', ['student_fee_assignment_id' => $assignment->id]) }}">Concession</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="11">No fee assignments match this selection.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $dues->links() }}</div>
</div>
@endsection
