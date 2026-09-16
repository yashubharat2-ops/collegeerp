@csrf
<div class="mt-6 rounded-lg border border-slate-200 p-4">
    <h3 class="font-semibold">Application Information</h3>
    <p class="text-xs text-slate-500 mt-1">Applicant, academic year and program are required. The enquiry link is optional for applications that originate without an enquiry.</p>

    <div class="mt-4 grid gap-5 md:grid-cols-2">
        @if(isset($application))
        <div class="md:col-span-2">
            <p class="text-sm"><span class="font-semibold">Application Number:</span> {{ $application->application_number }}</p>
            <p class="text-sm mt-1"><span class="font-semibold">Applicant:</span> {{ $application->applicant->first_name }} {{ $application->applicant->last_name }} — {{ $application->applicant->email ?? $application->applicant->phone }}</p>
            <p class="text-sm mt-1"><span class="font-semibold">Submitted:</span> {{ $application->submitted_at?->format('Y-m-d H:i') ?? 'Not submitted (draft)' }}</p>
        </div>
        @else
        <div>
            <label class="text-sm font-semibold" for="applicant_id">Applicant *</label>
            <select class="input mt-1" id="applicant_id" name="applicant_id" required>
                <option value="">— Select applicant —</option>
                @foreach($applicants as $applicant)
                    <option value="{{ $applicant->id }}" @selected((int) old('applicant_id') === $applicant->id)>{{ $applicant->first_name }} {{ $applicant->last_name }} — {{ $applicant->email ?? $applicant->phone }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('applicant_id'){{ $message }}@enderror</p>
        </div>
        @endif

        <div>
            <label class="text-sm font-semibold" for="academic_year_id">Academic Year *</label>
            <select class="input mt-1" id="academic_year_id" name="academic_year_id" required>
                <option value="">— Select academic year —</option>
                @foreach($academicYears as $ay)
                    <option value="{{ $ay->id }}" @selected((int) old('academic_year_id', $application->academic_year_id ?? 0) === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="program_id">Program *</label>
            <select class="input mt-1" id="program_id" name="program_id" required>
                <option value="">— Select program —</option>
                @foreach($programs as $prog)
                    <option value="{{ $prog->id }}" @selected((int) old('program_id', $application->program_id ?? 0) === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="enquiry_id">Link Enquiry (optional)</label>
            <select class="input mt-1" id="enquiry_id" name="enquiry_id">
                <option value="">— No enquiry link —</option>
                @foreach($enquiries as $enq)
                    <option value="{{ $enq->id }}" @selected((int) old('enquiry_id', $application->enquiry_id ?? 0) === $enq->id)>{{ $enq->enquiry_number }} — {{ $enq->applicant->first_name }} {{ $enq->applicant->last_name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('enquiry_id'){{ $message }}@enderror</p>
        </div>
        <div>
            <label class="text-sm font-semibold" for="status">Status *</label>
            <select class="input mt-1" id="status" name="status" required>
                <option value="draft" @selected(old('status', $application->status ?? 'draft') === 'draft')>Draft</option>
                <option value="submitted" @selected(old('status', $application->status ?? '') === 'submitted')>Submitted</option>
                <option value="under_review" @selected(old('status', $application->status ?? '') === 'under_review')>Under Review</option>
                <option value="approved" @selected(old('status', $application->status ?? '') === 'approved')>Approved</option>
                <option value="rejected" @selected(old('status', $application->status ?? '') === 'rejected')>Rejected</option>
                <option value="cancelled" @selected(old('status', $application->status ?? '') === 'cancelled')>Cancelled</option>
                <option value="admitted" @selected(old('status', $application->status ?? '') === 'admitted')>Admitted</option>
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-semibold" for="remarks">Remarks</label>
            <textarea class="input mt-1" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $application->remarks ?? '') }}</textarea>
            <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
        </div>
        <div class="md:col-span-2 flex gap-2">
            <button class="button" type="submit">{{ $submitLabel }}</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-applications.index') }}">Cancel</a>
        </div>
    </div>
</div>
