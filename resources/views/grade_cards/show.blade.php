@extends('layouts.app')

@section('title', 'Grade Card')

@section('content')
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">Grade Card — {{ $result->studentEnrollment?->student?->fullName() }}</h2>
        <p class="panel-subtitle">{{ $result->examination?->name }} · Published {{ $result->published_at?->format('M d, Y') ?? '—' }}</p>
    </div>
    <div class="flex gap-2">
        <button class="button" type="button" onclick="window.print()">Print Grade Card</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('grade-cards.index') }}">Back to grade cards</a>
    </div>
</div>

<div class="print-area mx-auto max-w-3xl">
    <div class="overflow-hidden rounded-2xl border-2 border-slate-300 bg-white shadow-sm">
        {{-- Institution header --}}
        <div class="border-b-2 border-slate-800 px-6 py-5 text-center sm:px-8">
            <p class="text-xl font-bold uppercase tracking-wide text-slate-900">{{ $college?->name ?? config('app.name', 'College ERP') }}</p>
            @if($college?->address)
                <p class="mt-1 text-xs text-slate-500">{{ $college->address }}</p>
            @endif
            @if($college?->email || $college?->phone)
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $college?->email }}{{ $college?->email && $college?->phone ? ' · ' : '' }}{{ $college?->phone }}
                </p>
            @endif
            <p class="mt-3 text-sm font-semibold uppercase tracking-widest text-slate-700">Grade Card</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $result->examination?->name }}</p>
            <p class="text-xs text-slate-500">
                {{ $result->academicYear?->name ?? '' }}{{ $result->academicYear && $result->academicTerm ? ' · ' : '' }}{{ $result->academicTerm?->name ?? '' }}
            </p>
        </div>

        {{-- Student / examination information --}}
        <div class="grid gap-x-8 gap-y-2 px-6 py-5 text-sm sm:grid-cols-2 sm:px-8">
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Student Name</span>
                <span class="text-right font-semibold">{{ $result->studentEnrollment?->student?->fullName() }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Student Number</span>
                <span class="text-right font-medium">{{ $result->studentEnrollment?->student?->student_number }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Enrollment Number</span>
                <span class="text-right font-medium">{{ $result->studentEnrollment?->enrollment_number }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Program</span>
                <span class="text-right font-medium">{{ $result->studentEnrollment?->program?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Section</span>
                <span class="text-right font-medium">{{ $result->studentEnrollment?->section?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Examination Code</span>
                <span class="text-right font-medium">{{ $result->examination?->code ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Grade Scale</span>
                <span class="text-right font-medium">{{ $result->gradeScale?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Result ID</span>
                <span class="text-right font-medium">#{{ $result->id }}</span>
            </div>
        </div>

        {{-- Subject-wise grades (rendered from the stored ExamResultItems; credits
             from the Subject master and grade points from the stored GradeScale) --}}
        <div class="px-6 pb-2 sm:px-8">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-y-2 border-slate-800 text-slate-700">
                        <th class="py-2.5 pr-2">#</th>
                        <th class="py-2.5 pr-2">Subject</th>
                        <th class="py-2.5 pr-2 text-right">Credits</th>
                        <th class="py-2.5 pr-2 text-right">Marks</th>
                        <th class="py-2.5 pr-2 text-center">Grade</th>
                        <th class="py-2.5 pr-2 text-center">Grade Point</th>
                        <th class="py-2.5 text-center">Result</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($result->items as $index => $item)
                        @php($subject = $item->examSchedule?->subject ?? $item->subject)
                        <tr class="border-b border-slate-200">
                            <td class="py-2.5 pr-2 text-slate-500">{{ $index + 1 }}</td>
                            <td class="py-2.5 pr-2 font-medium">{{ $subject?->name ?? '—' }}</td>
                            <td class="py-2.5 pr-2 text-right">{{ $subject?->credits ?? '—' }}</td>
                            <td class="py-2.5 pr-2 text-right">{{ $item->obtained_marks !== null ? $item->obtained_marks : '—' }} / {{ $item->max_marks }}</td>
                            <td class="py-2.5 pr-2 text-center font-semibold">{{ $item->grade ?? '—' }}</td>
                            <td class="py-2.5 pr-2 text-center">{{ ($item->grade !== null && array_key_exists($item->grade, $gradePoints)) ? ($gradePoints[$item->grade] ?? '—') : '—' }}</td>
                            <td class="py-2.5 text-center">{{ ucfirst($item->status) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-center text-slate-500" colspan="7">No subject results were calculated.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Overall outcome (stored values and stored grade-point lookups only) --}}
        <div class="grid grid-cols-2 gap-3 px-6 py-4 text-center sm:grid-cols-4 sm:px-8">
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-2 py-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Overall Grade</p>
                <p class="mt-1 text-base font-bold text-slate-900">{{ $result->overall_grade ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-2 py-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Grade Point</p>
                <p class="mt-1 text-base font-bold text-slate-900">{{ ($result->overall_grade !== null && array_key_exists($result->overall_grade, $gradePoints)) ? ($gradePoints[$result->overall_grade] ?? '—') : '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-2 py-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Result</p>
                <p class="mt-1 text-base font-bold text-slate-900">{{ ucfirst($result->result_status) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-2 py-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Published On</p>
                <p class="mt-1 text-base font-bold text-slate-900">{{ $result->published_at?->format('M d, Y') ?? '—' }}</p>
            </div>
        </div>

        {{-- Document footer --}}
        <div class="flex items-end justify-between gap-6 border-t border-slate-200 px-6 py-5 text-xs text-slate-500 sm:px-8">
            <div>
                <p>Date: {{ $result->published_at?->format('d M Y') ?? '—' }}</p>
                <p class="mt-2 max-w-xs">This grade card is generated from the published examination result. In case of any discrepancy, the published result record prevails.</p>
            </div>
            <div class="pt-8 text-center">
                <p class="border-t border-slate-400 px-6 pt-1 font-medium text-slate-700">Authorised Signatory</p>
            </div>
        </div>
    </div>

    <p class="no-print mt-4 text-xs text-slate-500">
        The grade card is read from the published result record. Use your browser's print dialog to print it or save it as a PDF.
    </p>
</div>
@endsection
