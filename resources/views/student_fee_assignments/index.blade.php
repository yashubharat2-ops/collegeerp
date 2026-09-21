@extends('layouts.app')

@section('title', 'Student Fee Assignment')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Fee Assignment</h2>
            <p class="panel-subtitle">Fee structures assigned to student enrollments. The assigned amount is captured when the plan is assigned and never re-priced afterwards.</p>
        </div>
        @can('create', App\Models\StudentFeeAssignment::class)
            <a class="button" href="{{ route('student-fee-assignments.create') }}">+ Assign fee structure</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('student-fee-assignments.index') }}">
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
            <label class="label" for="academic_year_id">Academic Year</label>
            <select class="input" id="academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((int) $selected['academic_year_id'] === $year->id)>{{ $year->name }} ({{ $year->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="program_id">Program</label>
            <select class="input" id="program_id" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((int) $selected['program_id'] === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="fee_structure_id">Fee Structure</label>
            <select class="input" id="fee_structure_id" name="fee_structure_id">
                <option value="">All structures</option>
                @foreach($feeStructures as $structure)
                    <option value="{{ $structure->id }}" @selected((int) $selected['fee_structure_id'] === $structure->id)>{{ $structure->name }} ({{ $structure->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <div class="flex gap-2">
                <select class="input" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach($assignmentStatuses as $status)
                        <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button class="button" type="submit">Filter</button>
            </div>
        </div>
    </form>

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
                    <th class="text-right">Collected</th>
                    <th class="text-right">Outstanding</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assignments as $assignment)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $assignment->studentEnrollment?->student?->fullName() ?? '—' }}</td>
                        <td>{{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}</td>
                        <td>{{ $assignment->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $assignment->studentEnrollment?->program?->code ?? '—' }}</td>
                        <td>{{ $assignment->feeStructure?->name ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) $assignment->assigned_amount, 2) }}</td>
                        <td class="text-right">{{ number_format((float) ($assignment->ledger['concession'] ?? 0), 2) }}</td>
                        <td class="text-right">{{ number_format((float) ($assignment->ledger['net_collected'] ?? 0), 2) }}</td>
                        <td class="text-right font-semibold">{{ number_format((float) ($assignment->ledger['outstanding'] ?? 0), 2) }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $assignment->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($assignment->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('create', App\Models\FeePayment::class)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.create', ['student_fee_assignment_id' => $assignment->id]) }}">Collect</a>
                                @endcan
                                @can('update', $assignment)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-fee-assignments.edit', $assignment) }}">Edit</a>
                                @endcan
                                @can('delete', $assignment)
                                    <form method="POST" action="{{ route('student-fee-assignments.destroy', $assignment) }}" onsubmit="return confirm('Delete this fee assignment? Recorded payments stay in the collection register.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="10">No fee structures assigned yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $assignments->links() }}</div>
</div>
@endsection
