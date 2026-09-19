@extends('layouts.app')
@section('title','Student Profile')
@section('content')
@php
    $currentEnrollment = $student->currentEnrollment();
    $tabs = [
        'profile' => 'Profile',
        'enrollments' => 'Enrollments',
        'academic-records' => 'Academic Records',
        'documents' => 'Documents',
        'id-card' => 'ID Card',
        'promotion' => 'Promotion',
        'transfer' => 'Transfer / TC',
        'history' => 'History',
    ];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'profile';
@endphp

{{-- ================= Student header (360°) ================= --}}
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <div class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl bg-indigo-100 text-xl font-bold text-indigo-700">
                @if($student->photo_path)
                    <img class="h-full w-full object-cover" src="{{ route('students.photo', $student) }}" alt="Photo of {{ $student->fullName() }}">
                @else
                    {{ strtoupper(substr($student->first_name, 0, 1).substr((string) $student->last_name, 0, 1)) }}
                @endif
            </div>
            <div>
                <h2 class="panel-title">{{ $student->fullName() }}</h2>
                <p class="panel-subtitle">
                    Student number <span class="font-semibold">{{ $student->student_number }}</span>
                    · Admitted {{ $student->admission_date?->format('d M Y') ?? '—' }}
                </p>
                <p class="mt-1 text-sm text-slate-600">
                    Current enrollment:
                    @if($currentEnrollment)
                        <span class="font-medium">{{ $currentEnrollment->enrollment_number }}</span>
                        · {{ $currentEnrollment->academicYear?->name ?? '—' }}
                        @if($currentEnrollment->program) · {{ $currentEnrollment->program->name }} @endif
                        @if($currentEnrollment->section) · Section {{ $currentEnrollment->section->name }} @endif
                    @else
                        <span class="text-slate-400">no active enrollment</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $student->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                {{ ucfirst($student->status) }}
            </span>
            @can('update', $student)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('students.edit', $student) }}">Edit</a>
            @endcan
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mt-6 flex flex-wrap gap-2 border-b border-slate-200 pb-2">
        @foreach($tabs as $key => $label)
            <a class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $tab === $key ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
               href="{{ route('students.show', ['student' => $student, 'tab' => $key]) }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- ================= 1. Profile ================= --}}
    @if($tab === 'profile')
        <div class="mt-6 grid gap-x-8 gap-y-2 text-sm md:grid-cols-2">
            <p><span class="font-semibold text-slate-500">Student number:</span> {{ $student->student_number }}</p>
            <p><span class="font-semibold text-slate-500">Status:</span> {{ ucfirst($student->status) }}</p>
            <p><span class="font-semibold text-slate-500">Email:</span> {{ $student->email ?? '—' }}</p>
            <p><span class="font-semibold text-slate-500">Phone:</span> {{ $student->phone ?? '—' }}</p>
            <p><span class="font-semibold text-slate-500">Alternate phone:</span> {{ $student->alternate_phone ?? '—' }}</p>
            <p><span class="font-semibold text-slate-500">Gender:</span> {{ $student->gender ?? '—' }}</p>
            <p><span class="font-semibold text-slate-500">Date of birth:</span> {{ $student->date_of_birth?->format('d M Y') ?? '—' }}</p>
            <p><span class="font-semibold text-slate-500">Admission date:</span> {{ $student->admission_date?->format('d M Y') ?? '—' }}</p>
            <p class="md:col-span-2"><span class="font-semibold text-slate-500">Address:</span> {{ implode(', ', array_filter([$student->address_line_1, $student->address_line_2, $student->city, $student->state, $student->postal_code, $student->country])) ?: '—' }}</p>
            @if($student->admissionApplication)
                <p><span class="font-semibold text-slate-500">Admission reference:</span> {{ $student->admissionApplication->application_number }}</p>
                <p><span class="font-semibold text-slate-500">Admission record:</span> {{ $student->admissionApplication->admission?->admission_number ?? '—' }}</p>
            @endif
        </div>
        <p class="mt-6 text-xs text-slate-500">
            Personal fields are the ones this project's Student schema holds (person data is snapshotted from the
            admission applicant at conversion time). Guardian and category fields are intentionally not invented here —
            they belong to a future schema decision. The portrait in the header and on the ID card comes from the
            existing <code>photo_path</code> column; this module adds no new person fields of its own.
        </p>

    {{-- ================= 2. Enrollments ================= --}}
    @elseif($tab === 'enrollments')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Enrollments</h3>
            @can('create', \App\Models\StudentEnrollment::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-enrollments.create', ['student_id' => $student->id]) }}">+ New enrollment</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-3">Number</th><th>Academic year</th><th>Program</th><th>Section</th><th>Date</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($student->enrollments as $enrollment)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $enrollment->enrollment_number }}</td>
                        <td>{{ $enrollment->academicYear?->name ?? '—' }}</td>
                        <td>{{ $enrollment->program?->name ?? '—' }}</td>
                        <td>{{ $enrollment->section?->name ?? '—' }}</td>
                        <td>{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td>
                        <td>{{ ucfirst($enrollment->status) }}</td>
                        <td class="text-right">
                            @can('update', $enrollment)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-enrollments.edit', $enrollment) }}">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="7">No enrollments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

    {{-- ================= 3. Academic Records ================= --}}
    @elseif($tab === 'academic-records')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Academic records</h3>
            @can('create', \App\Models\StudentAcademicRecord::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-academic-records.create', ['student_id' => $student->id]) }}">+ New record</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-3">Period</th><th>Program</th><th>Section</th><th>Academic</th><th>Promotion</th><th>Completion</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($student->academicRecords as $record)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $record->periodLabel() }}</td>
                        <td>{{ $record->program?->name ?? '—' }}</td>
                        <td>{{ $record->section?->name ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $record->academic_status)) }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $record->promotion_status)) }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $record->completion_status)) }}</td>
                        <td class="text-right">
                            @can('update', $record)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-academic-records.edit', $record) }}">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="7">No academic records yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

    {{-- ================= 4. Documents ================= --}}
    @elseif($tab === 'documents')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Documents</h3>
            @can('create', \App\Models\StudentDocument::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-documents.create', ['student_id' => $student->id]) }}">+ Upload document</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-3">Title</th><th>Type</th><th>File</th><th>Issued</th><th>Expires</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($student->documents as $document)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $document->title }}</td>
                        <td>{{ $document->documentType?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $document->original_filename }} · {{ $document->sizeInKb() }} KB</td>
                        <td class="text-xs">{{ $document->issue_date?->format('d M Y') ?? '—' }}</td>
                        <td class="text-xs">
                            {{ $document->expiry_date?->format('d M Y') ?? '—' }}
                            @if($document->isExpired())<span class="text-rose-600"> · expired</span>@endif
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($document->isPending()) bg-amber-100 text-amber-700
                                @elseif($document->isVerified()) bg-emerald-100 text-emerald-700
                                @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($document->verification_status) }}</span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('download', $document)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-documents.download', $document) }}">Download</a>
                                @endcan
                                @can('update', $document)
                                    <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('student-documents.edit', $document) }}">Edit</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="7">No documents uploaded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

    {{-- ================= 5. ID Card ================= --}}
    @elseif($tab === 'id-card')
        <div class="mt-6">
            @if(auth()->user()?->hasPermission('student_id_cards.generate'))
                <p class="text-sm text-slate-600">The ID card is generated on demand from this student's record and current enrollment — it is not a separate identity record.</p>
                <a class="button mt-4" href="{{ route('student-id-cards.show', $student) }}" target="_blank" rel="noopener">Generate / print ID card</a>
            @else
                <p class="text-sm text-slate-500">You do not have permission to generate ID cards.</p>
            @endif
        </div>

    {{-- ================= 6. Promotion ================= --}}
    @elseif($tab === 'promotion')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Promotions</h3>
            @can('create', \App\Models\StudentPromotion::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-promotions.create', ['student_id' => $student->id]) }}">+ New promotion</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-3">From</th><th>To</th><th>Section</th><th>Status</th><th>Approved</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($student->promotions as $promotion)
                    <tr class="border-b">
                        <td class="py-3">{{ $promotion->sourceAcademicYear?->name ?? '—' }}</td>
                        <td>{{ $promotion->targetAcademicYear?->name ?? '—' }}</td>
                        <td>{{ $promotion->targetSection?->name ?? '—' }}</td>
                        <td>{{ ucfirst($promotion->status) }}</td>
                        <td class="text-xs">{{ $promotion->approved_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="text-right">
                            @if($promotion->isPending())
                                @can('approve', $promotion)
                                    <form class="inline" method="POST" action="{{ route('student-promotions.approve', $promotion) }}"
                                          onsubmit="return confirm(@js('Approve this promotion? A new enrollment will be created and the current one marked completed.'))">
                                        @csrf
                                        <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Approve</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="6">No promotions recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

    {{-- ================= 7. Transfer / TC ================= --}}
    @elseif($tab === 'transfer')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Transfer / TC</h3>
            @can('create', \App\Models\StudentTransfer::class)
                <a class="button !px-3 !py-2 text-xs" href="{{ route('student-transfers.create', ['student_id' => $student->id]) }}">+ New transfer request</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-3">Transfer date</th><th>Destination</th><th>Request</th><th>TC number</th><th>TC status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                @forelse($student->transfers as $transfer)
                    <tr class="border-b">
                        <td class="py-3">{{ $transfer->transfer_date?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $transfer->destination_institution ?? '—' }}</td>
                        <td>{{ ucfirst($transfer->status) }}</td>
                        <td class="font-medium">{{ $transfer->tc_number ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $transfer->tc_status)) }}</td>
                        <td class="text-right">
                            @if($transfer->hasTcFile())
                                @can('download', $transfer)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-transfers.download', $transfer) }}">TC file</a>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="6">No transfer requests.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-slate-500">A transfer never deletes the student: statuses change and the record, enrollments and documents are preserved.</p>

    {{-- ================= 8. History ================= --}}
    @elseif($tab === 'history')
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold">Lifecycle history</h3>
            <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-history.show', $student) }}">Open full history</a>
        </div>
        <ol class="mt-4 space-y-3 border-l border-slate-200 pl-5">
            @forelse($historyEvents as $event)
                <li class="relative">
                    <span class="absolute -left-[26px] top-1.5 h-2.5 w-2.5 rounded-full bg-indigo-400"></span>
                    <p class="text-sm font-medium text-slate-800">{{ $event->label }}</p>
                    <p class="text-xs text-slate-500">{{ $event->occurredAtTime() }} · {{ ucfirst($event->category) }}</p>
                    @if($event->description !== '')<p class="text-sm text-slate-600">{{ $event->description }}</p>@endif
                </li>
            @empty
                <li class="text-sm text-slate-500">No history recorded yet.</li>
            @endforelse
        </ol>
    @endif
</div>
@endsection
