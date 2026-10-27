@php
    /*
     * ONE previous-qualification row.
     *
     * Used three ways: for each stored row, for `old()` input after a failed
     * validation, and inside the <template> the "Add qualification" button clones
     * (with __INDEX__ as the placeholder index).
     *
     * Grade and marks are alternatives: entering a grade makes the percentage
     * field irrelevant (the server discards any derived percentage), while a
     * marks pair produces one automatically.
     */
    $prefix = 'qualifications['.$index.']';
    $get = fn (string $key) => $row[$key] ?? null;
    $level = $get('level');

    $subjectsText = $get('subjects_text');

    if ($subjectsText === null && is_array($get('subjects'))) {
        $subjectsText = collect($get('subjects'))
            ->map(function ($subject) {
                if (is_string($subject)) {
                    return $subject;
                }

                $parts = [trim((string) ($subject['name'] ?? ''))];

                if (($subject['max_marks'] ?? null) !== null) {
                    $parts[] = $subject['max_marks'];
                }

                if (($subject['marks_obtained'] ?? null) !== null) {
                    $parts[] = $subject['marks_obtained'];
                }

                return implode(' | ', $parts);
            })
            ->filter()
            ->implode("\n");
    }
@endphp

<div class="erp-qual-card" data-erp-qualification data-index="{{ $index }}">
    <div class="erp-qual-card__head">
        <div>
            <p class="erp-qual-card__title">Qualification {{ is_numeric($index) ? ((int) $index + 1) : '' }}</p>
            <p class="erp-qual-card__meta">One row per result. Leave a row empty to ignore it.</p>
        </div>
        <div>
            @if(! empty($get('id')))
                <input type="hidden" name="{{ $prefix }}[id]" value="{{ $get('id') }}">
            @endif
            <button class="erp-btn erp-btn--ghost erp-btn--sm" type="button" data-erp-qualification-remove>
                Remove
            </button>
        </div>
    </div>

    <div class="erp-grid">
        <div class="erp-field erp-col-3">
            <label class="erp-label" for="q{{ $index }}_level">Level</label>
            <select class="erp-input" id="q{{ $index }}_level" name="{{ $prefix }}[level]" data-qual="level">
                <option value="">— Select —</option>
                @foreach($qualificationLevels as $key => $label)
                    <option value="{{ $key }}" @selected($level === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="erp-error">@error('qualifications.'.$index.'.level'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-3">
            <label class="erp-label" for="q{{ $index }}_qualification">Qualification</label>
            <input class="erp-input" id="q{{ $index }}_qualification" name="{{ $prefix }}[qualification]"
                   value="{{ $get('qualification') }}" maxlength="150" placeholder="e.g. Senior Secondary">
            <p class="erp-error">@error('qualifications.'.$index.'.qualification'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_year">Exam year</label>
            <input class="erp-input" id="q{{ $index }}_year" name="{{ $prefix }}[examination_year]"
                   value="{{ $get('examination_year') }}" inputmode="numeric" maxlength="4" placeholder="2024">
            <p class="erp-error">@error('qualifications.'.$index.'.examination_year'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-4">
            <label class="erp-label" for="q{{ $index }}_board">Board / University</label>
            <input class="erp-input" id="q{{ $index }}_board" name="{{ $prefix }}[board_university]"
                   value="{{ $get('board_university') }}" maxlength="150">
            <p class="erp-error">@error('qualifications.'.$index.'.board_university'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-4">
            <label class="erp-label" for="q{{ $index }}_institution">Institution</label>
            <input class="erp-input" id="q{{ $index }}_institution" name="{{ $prefix }}[institution]"
                   value="{{ $get('institution') }}" maxlength="200">
            <p class="erp-error">@error('qualifications.'.$index.'.institution'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_roll">Roll no.</label>
            <input class="erp-input" id="q{{ $index }}_roll" name="{{ $prefix }}[roll_number]"
                   value="{{ $get('roll_number') }}" maxlength="50">
            <p class="erp-error">@error('qualifications.'.$index.'.roll_number'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_reg">Registration no.</label>
            <input class="erp-input" id="q{{ $index }}_reg" name="{{ $prefix }}[registration_number]"
                   value="{{ $get('registration_number') }}" maxlength="80">
            <p class="erp-error">@error('qualifications.'.$index.'.registration_number'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_obtained">Marks obtained</label>
            <input class="erp-input" id="q{{ $index }}_obtained" name="{{ $prefix }}[marks_obtained]"
                   value="{{ $get('marks_obtained') }}" inputmode="decimal" data-qual="marks_obtained">
            <p class="erp-error">@error('qualifications.'.$index.'.marks_obtained'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_max">Maximum marks</label>
            <input class="erp-input" id="q{{ $index }}_max" name="{{ $prefix }}[max_marks]"
                   value="{{ $get('max_marks') }}" inputmode="decimal" data-qual="max_marks">
            <p class="erp-error">@error('qualifications.'.$index.'.max_marks'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_pct">Percentage</label>
            <input class="erp-input" id="q{{ $index }}_pct" name="{{ $prefix }}[percentage]"
                   value="{{ $get('percentage') }}" readonly tabindex="-1" data-qual="percentage"
                   placeholder="Calculated">
            <p class="erp-error">@error('qualifications.'.$index.'.percentage'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_grade">Grade</label>
            <input class="erp-input" id="q{{ $index }}_grade" name="{{ $prefix }}[grade]"
                   value="{{ $get('grade') }}" maxlength="30" data-qual="grade">
            <p class="erp-error">@error('qualifications.'.$index.'.grade'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-2">
            <label class="erp-label" for="q{{ $index }}_letter">Grade letter</label>
            <input class="erp-input" id="q{{ $index }}_letter" name="{{ $prefix }}[grade_letter]"
                   value="{{ $get('grade_letter') }}" maxlength="10" data-qual="grade_letter">
            <p class="erp-error">@error('qualifications.'.$index.'.grade_letter'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-3">
            <label class="erp-label" for="q{{ $index }}_result">Result</label>
            <select class="erp-input" id="q{{ $index }}_result" name="{{ $prefix }}[result_status]">
                <option value="">— Select —</option>
                @foreach($qualificationResultStatuses as $status)
                    <option value="{{ $status }}" @selected($get('result_status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <p class="erp-error">@error('qualifications.'.$index.'.result_status'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-9">
            <label class="erp-label" for="q{{ $index }}_subjects">Subjects</label>
            <textarea class="erp-input" id="q{{ $index }}_subjects" name="{{ $prefix }}[subjects_text]" rows="2"
                      placeholder="One per line: Mathematics | 100 | 88">{{ $subjectsText }}</textarea>
            <p class="erp-help">Name, then maximum marks, then marks obtained — separated by |. Marks are optional.</p>
            <p class="erp-error">@error('qualifications.'.$index.'.subjects_text'){{ $message }}@enderror</p>
        </div>

        <div class="erp-field erp-col-12">
            <label class="erp-label" for="q{{ $index }}_remarks">Remarks</label>
            <input class="erp-input" id="q{{ $index }}_remarks" name="{{ $prefix }}[remarks]"
                   value="{{ $get('remarks') }}" maxlength="500">
            <p class="erp-error">@error('qualifications.'.$index.'.remarks'){{ $message }}@enderror</p>
        </div>
    </div>
</div>
