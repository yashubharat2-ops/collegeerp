@csrf
<div class="mt-6 space-y-8">
    {{-- Applicant Section (only for create) --}}
    @if(!isset($enquiry))
    <div class="rounded-lg border border-slate-200 p-4">
        <h3 class="font-semibold">Applicant Information</h3>
        <p class="text-xs text-slate-500 mt-1">Enter minimal applicant details or select existing applicant. Phone/email will be checked for duplicates within the active college.</p>

        <div class="mt-4">
            <label class="text-sm font-semibold" for="applicant_id">Use Existing Applicant (optional)</label>
            <select class="input mt-1" id="applicant_id" name="applicant_id">
                <option value="">— Create new applicant —</option>
                @foreach($recentApplicants ?? [] as $ra)
                    <option value="{{ $ra->id }}" @selected((int) old('applicant_id') === $ra->id)>{{ $ra->first_name }} {{ $ra->last_name }} — {{ $ra->email ?? $ra->phone }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-rose-600">@error('applicant_id'){{ $message }}@enderror</p>
        </div>

        <div id="new-applicant-fields" class="mt-4 grid gap-5 md:grid-cols-2">
            <div>
                <label class="text-sm font-semibold" for="applicant_first_name">First Name *</label>
                <input class="input mt-1" id="applicant_first_name" name="applicant_first_name" value="{{ old('applicant_first_name') }}" maxlength="255">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_first_name'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_middle_name">Middle Name</label>
                <input class="input mt-1" id="applicant_middle_name" name="applicant_middle_name" value="{{ old('applicant_middle_name') }}" maxlength="255">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_middle_name'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_last_name">Last Name</label>
                <input class="input mt-1" id="applicant_last_name" name="applicant_last_name" value="{{ old('applicant_last_name') }}" maxlength="255">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_last_name'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_email">Email</label>
                <input class="input mt-1" id="applicant_email" name="applicant_email" type="email" value="{{ old('applicant_email') }}" maxlength="255">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_email'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_phone">Phone</label>
                <input class="input mt-1" id="applicant_phone" name="applicant_phone" value="{{ old('applicant_phone') }}" maxlength="30">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_phone'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_alternate_phone">Alternate Phone</label>
                <input class="input mt-1" id="applicant_alternate_phone" name="applicant_alternate_phone" value="{{ old('applicant_alternate_phone') }}" maxlength="30">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_alternate_phone'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_gender">Gender</label>
                <select class="input mt-1" id="applicant_gender" name="applicant_gender">
                    <option value="">— Select —</option>
                    <option value="male" @selected(old('applicant_gender') === 'male')>Male</option>
                    <option value="female" @selected(old('applicant_gender') === 'female')>Female</option>
                    <option value="other" @selected(old('applicant_gender') === 'other')>Other</option>
                    <option value="prefer_not_to_say" @selected(old('applicant_gender') === 'prefer_not_to_say')>Prefer not to say</option>
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('applicant_gender'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="applicant_date_of_birth">Date of Birth</label>
                <input class="input mt-1" id="applicant_date_of_birth" name="applicant_date_of_birth" type="date" value="{{ old('applicant_date_of_birth') }}">
                <p class="mt-1 text-xs text-rose-600">@error('applicant_date_of_birth'){{ $message }}@enderror</p>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-semibold" for="applicant_address">Address</label>
                <textarea class="input mt-1" id="applicant_address" name="applicant_address" rows="2" maxlength="2000">{{ old('applicant_address') }}</textarea>
                <p class="mt-1 text-xs text-rose-600">@error('applicant_address'){{ $message }}@enderror</p>
            </div>
        </div>

        <div id="duplicate-warning" class="mt-4 hidden rounded-lg bg-amber-50 p-3 text-sm text-amber-800 border border-amber-200">
            <p class="font-semibold">Possible duplicate applicants found in this college:</p>
            <ul id="duplicate-list" class="mt-2 list-disc pl-5"></ul>
            <p class="mt-2 text-xs">If one of these is the same person, please select it from "Use Existing Applicant" to avoid duplicate records.</p>
        </div>
    </div>
    @endif

    {{-- Enquiry Section --}}
    <div class="rounded-lg border border-slate-200 p-4">
        <h3 class="font-semibold">Enquiry Information</h3>
        <p class="text-xs text-slate-500 mt-1">Academic year and program are optional for general enquiries, but required for program-specific applications.</p>

        <div class="mt-4 grid gap-5 md:grid-cols-2">
            @if(isset($enquiry))
            <div class="md:col-span-2">
                <p class="text-sm"><span class="font-semibold">Enquiry Number:</span> {{ $enquiry->enquiry_number }}</p>
                <p class="text-sm mt-1"><span class="font-semibold">Applicant:</span> {{ $enquiry->applicant->first_name }} {{ $enquiry->applicant->last_name }} — {{ $enquiry->applicant->email ?? $enquiry->applicant->phone }}</p>
            </div>
            @endif

            <div>
                <label class="text-sm font-semibold" for="academic_year_id">Academic Year</label>
                <select class="input mt-1" id="academic_year_id" name="academic_year_id">
                    <option value="">— General enquiry (no year) —</option>
                    @foreach($academicYears as $ay)
                        <option value="{{ $ay->id }}" @selected((int) old('academic_year_id', $enquiry->academic_year_id ?? 0) === $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="program_id">Interested Program</label>
                <select class="input mt-1" id="program_id" name="program_id">
                    <option value="">— Undecided / General —</option>
                    @foreach($programs as $prog)
                        <option value="{{ $prog->id }}" @selected((int) old('program_id', $enquiry->program_id ?? 0) === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="source">Source</label>
                <input class="input mt-1" id="source" name="source" value="{{ old('source', $enquiry->source ?? '') }}" maxlength="100" placeholder="e.g. website, referral, walk_in">
                <p class="mt-1 text-xs text-rose-600">@error('source'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="status">Status *</label>
                <select class="input mt-1" id="status" name="status" required>
                    <option value="new" @selected(old('status', $enquiry->status ?? 'new') === 'new')>New</option>
                    <option value="contacted" @selected(old('status', $enquiry->status ?? '') === 'contacted')>Contacted</option>
                    <option value="followed_up" @selected(old('status', $enquiry->status ?? '') === 'followed_up')>Followed Up</option>
                    <option value="converted" @selected(old('status', $enquiry->status ?? '') === 'converted')>Converted</option>
                    <option value="closed" @selected(old('status', $enquiry->status ?? '') === 'closed')>Closed</option>
                    <option value="dropped" @selected(old('status', $enquiry->status ?? '') === 'dropped')>Dropped</option>
                </select>
                <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="enquired_at">Enquired At</label>
                <input class="input mt-1" id="enquired_at" name="enquired_at" type="datetime-local" value="{{ old('enquired_at', isset($enquiry->enquired_at) ? $enquiry->enquired_at->format('Y-m-d\TH:i') : '') }}">
                <p class="mt-1 text-xs text-rose-600">@error('enquired_at'){{ $message }}@enderror</p>
            </div>
            <div>
                <label class="text-sm font-semibold" for="next_follow_up_at">Next Follow Up</label>
                <input class="input mt-1" id="next_follow_up_at" name="next_follow_up_at" type="datetime-local" value="{{ old('next_follow_up_at', isset($enquiry->next_follow_up_at) ? $enquiry->next_follow_up_at->format('Y-m-d\TH:i') : '') }}">
                <p class="mt-1 text-xs text-rose-600">@error('next_follow_up_at'){{ $message }}@enderror</p>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-semibold" for="remarks">Remarks</label>
                <textarea class="input mt-1" id="remarks" name="remarks" rows="3" maxlength="2000">{{ old('remarks', $enquiry->remarks ?? '') }}</textarea>
                <p class="mt-1 text-xs text-rose-600">@error('remarks'){{ $message }}@enderror</p>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-enquiries.index') }}">Cancel</a>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const applicantSelect = document.getElementById('applicant_id');
    const newFields = document.getElementById('new-applicant-fields');
    const phoneInput = document.getElementById('applicant_phone');
    const emailInput = document.getElementById('applicant_email');
    const warningBox = document.getElementById('duplicate-warning');
    const duplicateList = document.getElementById('duplicate-list');

    function toggleNewFields() {
        if (!applicantSelect || !newFields) return;
        if (applicantSelect.value) {
            newFields.style.display = 'none';
            if (warningBox) warningBox.classList.add('hidden');
        } else {
            newFields.style.display = 'grid';
        }
    }

    if (applicantSelect) {
        applicantSelect.addEventListener('change', toggleNewFields);
        toggleNewFields();
    }

    let debounceTimer;
    function checkDuplicates() {
        if (!phoneInput || !emailInput) return;
        const phone = phoneInput.value.trim();
        const email = emailInput.value.trim();
        if (!phone && !email) {
            if (warningBox) warningBox.classList.add('hidden');
            return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            fetch(`{{ route('admission-enquiries.duplicate-check') }}?phone=${encodeURIComponent(phone)}&email=${encodeURIComponent(email)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.duplicates && data.duplicates.length > 0) {
                    duplicateList.innerHTML = '';
                    data.duplicates.forEach(d => {
                        const li = document.createElement('li');
                        // Use textContent to prevent XSS, never innerHTML with user data
                        li.textContent = `${d.name} — ${d.email || ''} ${d.phone || ''} (ID: ${d.id})`;
                        duplicateList.appendChild(li);
                    });
                    warningBox.classList.remove('hidden');
                } else {
                    warningBox.classList.add('hidden');
                }
            })
            .catch(() => {});
        }, 500);
    }

    if (phoneInput) phoneInput.addEventListener('blur', checkDuplicates);
    if (emailInput) emailInput.addEventListener('blur', checkDuplicates);
});
</script>
@endpush
