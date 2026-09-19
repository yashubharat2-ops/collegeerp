@extends('layouts.app')
@section('title','Student')
@section('content')
<div class="panel max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">{{ $student->first_name }} {{ $student->middle_name }} {{ $student->last_name }}</h2>
            <p class="panel-subtitle">Student number <span class="font-semibold">{{ $student->student_number }}</span> · Admitted {{ $student->admission_date?->format('d M Y') ?? '—' }}</p>
        </div>
        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $student->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
            {{ ucfirst($student->status) }}
        </span>
    </div>

    <div class="mt-6 grid gap-x-8 gap-y-2 text-sm md:grid-cols-2">
        <p><span class="font-semibold text-slate-500">Email:</span> {{ $student->email ?? '—' }}</p>
        <p><span class="font-semibold text-slate-500">Phone:</span> {{ $student->phone ?? '—' }}</p>
        <p><span class="font-semibold text-slate-500">Alternate phone:</span> {{ $student->alternate_phone ?? '—' }}</p>
        <p><span class="font-semibold text-slate-500">Gender:</span> {{ $student->gender ?? '—' }}</p>
        <p><span class="font-semibold text-slate-500">Date of birth:</span> {{ $student->date_of_birth?->format('d M Y') ?? '—' }}</p>
        <p><span class="font-semibold text-slate-500">Address:</span> {{ implode(', ', array_filter([$student->address_line_1, $student->address_line_2, $student->city, $student->state, $student->postal_code, $student->country])) ?: '—' }}</p>
        @if($student->admissionApplication)
            <p class="md:col-span-2"><span class="font-semibold text-slate-500">Converted from application:</span> {{ $student->admissionApplication->application_number }}</p>
        @endif
    </div>

    <div class="mt-8">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold">Enrollments</h3>
            @can('create', \App\Models\StudentEnrollment::class)
                <a class="button" href="{{ route('student-enrollments.create', ['student_id' => $student->id]) }}">+ New enrollment</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3">Number</th>
                        <th>Academic year</th>
                        <th>Program</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($student->enrollments as $enrollment)
                        <tr class="border-b">
                            <td class="py-3 font-medium">
                                @can('view', $enrollment)
                                    <a class="text-indigo-600 hover:underline" href="{{ route('student-enrollments.edit', $enrollment) }}">{{ $enrollment->enrollment_number }}</a>
                                @else
                                    {{ $enrollment->enrollment_number }}
                                @endcan
                            </td>
                            <td>{{ $enrollment->academicYear?->name ?? '—' }}</td>
                            <td>{{ $enrollment->program?->name ?? '—' }}</td>
                            <td>{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td>
                            <td>{{ ucfirst($enrollment->status) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-6 text-slate-500" colspan="5">No enrollments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
