@extends('layouts.app')

@section('title', 'Allocate Fee Heads')

@section('content')
<div class="panel" data-fee-allocation>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Allocate Fee Heads</h2>
            <p class="panel-subtitle">Preview immutable per-head assignments before confirmation. No payment or receipt is created.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-fee-assignments.index') }}">Back to assignments</a>
    </div>

    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('student-fee-assignments.allocate')"
        :preserve="['fee_structure_id' => $selectedStructureId]"
        title="Filter Students for Fee Allocation"
    />

    @if($errors->any())
        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('student-fee-assignments.allocation.preview') }}" class="mt-6 space-y-6">
        @csrf
        @foreach([
            'student_number', 'admission_date_from', 'admission_date_to', 'academic_year_id',
            'department_id', 'program_id', 'academic_context', 'enrollment_status', 'student_status',
            'student_name', 'unique_student_id', 'email', 'apaar_id', 'enrollment_number',
            'mobile_number', 'aadhaar_last4', 'father_name', 'mother_name',
        ] as $filterName)
            @if(is_scalar(request()->query($filterName)) && request()->query($filterName) !== '')
                <input type="hidden" name="{{ $filterName }}" value="{{ request()->query($filterName) }}">
            @endif
        @endforeach

        <section aria-labelledby="fee-head-configuration" class="rounded-xl border border-slate-200 p-4">
            <h3 id="fee-head-configuration" class="text-sm font-semibold text-slate-800">1. Choose Fee Structure and Fee Heads</h3>
            <p class="mt-1 text-xs text-slate-500">Amounts come from active Fee Heads, not Fee Categories or browser-supplied values.</p>
            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div>
                    <label class="label" for="allocation_fee_structure_id">Fee Structure</label>
                    <select class="input" id="allocation_fee_structure_id" name="fee_structure_id" required data-allocation-structure>
                        <option value="">Choose a structure</option>
                        @foreach($structures as $structure)
                            <option value="{{ $structure->id }}" @selected((string) $selectedStructureId === (string) $structure->id)>{{ $structure->name }} ({{ $structure->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="allocation_assigned_at">Assignment date</label>
                    <input class="input" id="allocation_assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at', now()->toDateString()) }}" required>
                </div>
                <div>
                    <label class="label" for="allocation_status">Assignment status</label>
                    <select class="input" id="allocation_status" name="status" required>
                        @foreach(App\Models\StudentFeeAssignment::PAYABLE_STATUSES as $status)
                            <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <label class="label" for="allocation_remarks">Remarks (optional)</label>
                <textarea class="input" id="allocation_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
            </div>

            <div class="mt-4 space-y-3">
                @foreach($structures as $structure)
                    <fieldset class="rounded-lg border border-slate-200 p-3" data-allocation-heads="{{ $structure->id }}">
                        <legend class="px-1 text-xs font-semibold text-slate-700">{{ $structure->name }} — Fee Heads</legend>
                        @forelse($structure->activeItems as $head)
                            <label class="mr-4 inline-flex items-center gap-2 py-1 text-sm">
                                <input type="checkbox" name="fee_head_ids[]" value="{{ $head->id }}" data-allocation-head class="rounded border-slate-300 text-indigo-600">
                                <span>{{ $head->name }}</span>
                                <span class="font-semibold">{{ number_format((float) $head->amount, 2) }}</span>
                            </label>
                        @empty
                            <p class="text-xs text-slate-500">This structure has no active Fee Heads.</p>
                        @endforelse
                    </fieldset>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="fee-allocation-scope" class="rounded-xl border border-slate-200 p-4">
            <h3 id="fee-allocation-scope" class="text-sm font-semibold text-slate-800">2. Choose allocation scope</h3>
            <div class="mt-3 flex flex-wrap gap-5 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="selection_mode" value="selected" checked data-allocation-scope>
                    <span>Checked enrollments on this page (maximum {{ App\Domain\Finance\Services\FeeHeadAllocationService::MAX_EXPLICIT_IDS }})</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="selection_mode" value="all_filtered" data-allocation-scope>
                    <span>ALL enrollments matching the filters (not just this page)</span>
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-500">Both modes re-run tenant scope, filters and eligibility on the server. “All filtered” ignores checkbox IDs.</p>
        </section>

        <div class="erp-list-scroll">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500">
                    <th class="w-10 py-2"><input type="checkbox" data-allocation-select-page aria-label="Select all enrollments on this page" class="rounded border-slate-300 text-indigo-600"></th>
                    <th>REG.NO</th><th>NAME OF STUDENT</th><th>ENROLLMENT NO.</th><th>Year / Program</th><th>ACADEMIC STATUS</th>
                </tr></thead>
                <tbody>
                @forelse($enrollments as $enrollment)
                    <tr class="border-b">
                        <td class="py-2"><input type="checkbox" name="student_enrollment_ids[]" value="{{ $enrollment->id }}" data-allocation-row aria-label="Select {{ $enrollment->student?->student_number }} / {{ $enrollment->enrollment_number }}" class="rounded border-slate-300 text-indigo-600"></td>
                        <td>{{ $enrollment->student?->student_number }}</td>
                        <td>{{ $enrollment->student?->fullName() }}</td>
                        <td>{{ $enrollment->enrollment_number }}</td>
                        <td>{{ $enrollment->academicYear?->name }} · {{ $enrollment->program?->code ?? '—' }}</td>
                        <td>{{ ucfirst($enrollment->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-slate-500">No enrollments match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-xs text-slate-500">Page {{ $enrollments->currentPage() }} of {{ $enrollments->lastPage() }} · {{ $enrollments->total() }} matching enrollments {{ $enrollments->links() }}</div>
            <button class="button" type="submit">Preview allocation</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script type="module" src="{{ asset('js/erp-fee-allocation.js') }}"></script>
@endpush
