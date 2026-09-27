@extends('layouts.app')
@section('title', 'Student Profile / Detail Report')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <h2 class="panel-title">Student Profile / Detail Report</h2>
        <p class="panel-subtitle">{{ $student->student_number }} · {{ $student->fullName() }} · {{ ucfirst($student->status) }} — read-only, active college</p>
        @include('student_reports._navigation', ['selected' => 'profile'])
        <div class="no-print mt-4 flex gap-4 text-sm font-semibold text-indigo-700">
            <a href="{{ route('student-reports.index', ['report' => 'profile']) }}" class="hover:underline">Choose another student</a>
            <a href="{{ route('student-reports.history', $student) }}" class="hover:underline">View lifecycle history</a>
        </div>
    </div>

    <div class="panel">
        <h3 class="panel-title">Student details</h3>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-slate-500">Full name</dt><dd class="font-medium">{{ $student->fullName() }}</dd></div>
            <div><dt class="text-slate-500">Student number</dt><dd class="font-medium">{{ $student->student_number }}</dd></div>
            <div><dt class="text-slate-500">Status</dt><dd class="font-medium">{{ ucfirst($student->status) }}</dd></div>
            <div><dt class="text-slate-500">Gender</dt><dd>{{ $student->gender ? ucfirst(str_replace('_', ' ', $student->gender)) : 'Not recorded' }}</dd></div>
            <div><dt class="text-slate-500">Date of birth</dt><dd>{{ $student->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Admission date</dt><dd>{{ $student->admission_date?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Email</dt><dd>{{ $student->email ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd>{{ $student->phone ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Address</dt><dd>{{ implode(', ', array_filter([$student->address_line_1, $student->address_line_2, $student->city, $student->state, $student->postal_code, $student->country])) ?: '—' }}</dd></div>
        </dl>
    </div>

    @php($application = $student->admissionApplication)
    @php($admission = $application?->admission)
    <div class="panel">
        <h3 class="panel-title">Admission provenance</h3>
        @if($application)
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-slate-500">Application</dt><dd class="font-medium">{{ $application->application_number }} · {{ ucfirst(str_replace('_', ' ', $application->status)) }}</dd></div>
                <div><dt class="text-slate-500">Admission number</dt><dd>{{ $admission?->admission_number ?? 'No official admission record' }}</dd></div>
                <div><dt class="text-slate-500">Admission status</dt><dd>{{ $admission ? ucfirst($admission->status) : '—' }}</dd></div>
                <div><dt class="text-slate-500">Admission year</dt><dd>{{ $admission?->academicYear?->name ?? $application->academicYear?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Program</dt><dd>{{ $admission?->program?->name ?? $application->program?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Department</dt><dd>{{ $admission?->program?->department?->name ?? '—' }}</dd></div>
            </dl>
        @else
            <p class="mt-4 text-sm text-slate-500">No admission application is linked to this student.</p>
        @endif
    </div>

    <div class="panel">
        <h3 class="panel-title">Enrollment history</h3>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Enrollment</th><th class="pr-4">Date</th><th class="pr-4">Academic year</th><th class="pr-4">Program / department</th><th class="pr-4">Class / section</th><th>Status</th></tr></thead><tbody>
            @forelse($student->enrollments->sortBy([['enrollment_date', 'asc'], ['id', 'asc']]) as $enrollment)
                <tr class="border-b"><td class="py-2 pr-4">{{ $enrollment->enrollment_number }}</td><td class="pr-4">{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td><td class="pr-4">{{ $enrollment->academicYear?->name ?? '—' }}</td><td class="pr-4">{{ $enrollment->program?->name ?? 'No program' }} · {{ $enrollment->program?->department?->name ?? 'No department' }}</td><td class="pr-4">{{ $enrollment->section?->name ?? '—' }}</td><td>{{ ucfirst($enrollment->status) }}</td></tr>
            @empty
                <tr><td colspan="6" class="py-4 text-slate-500">No enrollments recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>

    <div class="panel">
        <h3 class="panel-title">Academic records</h3>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Period</th><th class="pr-4">Program / section</th><th class="pr-4">Academic status</th><th class="pr-4">Promotion</th><th>Completion</th></tr></thead><tbody>
            @forelse($student->academicRecords as $record)
                <tr class="border-b"><td class="py-2 pr-4">{{ $record->periodLabel() }}</td><td class="pr-4">{{ $record->program?->name ?? '—' }} · {{ $record->section?->name ?? '—' }}</td><td class="pr-4">{{ ucfirst($record->academic_status) }}</td><td class="pr-4">{{ ucfirst(str_replace('_', ' ', $record->promotion_status)) }}</td><td>{{ ucfirst($record->completion_status) }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-4 text-slate-500">No academic records recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>

    <div class="panel">
        <h3 class="panel-title">Student documents status</h3>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Document / type</th><th class="pr-4">Uploaded</th><th class="pr-4">Verification</th><th>Expiry</th></tr></thead><tbody>
            @forelse($student->documents as $document)
                <tr class="border-b"><td class="py-2 pr-4">{{ $document->title }}<span class="block text-xs text-slate-500">{{ $document->documentType?->name ?? 'Unclassified' }}</span></td><td class="pr-4">{{ $document->created_at?->format('d M Y') ?? '—' }}</td><td class="pr-4">{{ ucfirst($document->verification_status) }}</td><td>{{ $document->expiry_date?->format('d M Y') ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-4 text-slate-500">No student documents recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>

    <div class="panel">
        <h3 class="panel-title">Promotions</h3>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">From</th><th class="pr-4">To</th><th class="pr-4">Effective date</th><th class="pr-4">Status</th><th>New enrollment</th></tr></thead><tbody>
            @forelse($student->promotions as $promotion)
                <tr class="border-b"><td class="py-2 pr-4">{{ $promotion->sourceAcademicYear?->name ?? '—' }} · {{ $promotion->sourceProgram?->name ?? '—' }} · {{ $promotion->sourceSection?->name ?? '—' }}</td><td class="pr-4">{{ $promotion->targetAcademicYear?->name ?? '—' }} · {{ $promotion->targetProgram?->name ?? '—' }} · {{ $promotion->targetSection?->name ?? '—' }}</td><td class="pr-4">{{ $promotion->effective_date?->format('d M Y') ?? '—' }}</td><td class="pr-4">{{ ucfirst($promotion->status) }}</td><td>{{ $promotion->targetEnrollment?->enrollment_number ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-4 text-slate-500">No promotions recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>

    <div class="panel">
        <h3 class="panel-title">Transfer / TC</h3>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Transfer date</th><th class="pr-4">Destination</th><th class="pr-4">Request</th><th class="pr-4">TC number</th><th>TC status / issue date</th></tr></thead><tbody>
            @forelse($student->transfers as $transfer)
                <tr class="border-b"><td class="py-2 pr-4">{{ $transfer->transfer_date?->format('d M Y') ?? '—' }}</td><td class="pr-4">{{ $transfer->destination_institution ?? '—' }}</td><td class="pr-4">{{ ucfirst($transfer->status) }}</td><td class="pr-4">{{ $transfer->tc_number ?? '—' }}</td><td>{{ ucfirst($transfer->tc_status) }} · {{ $transfer->tc_issue_date?->format('d M Y') ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-4 text-slate-500">No transfers recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div>
@endsection
