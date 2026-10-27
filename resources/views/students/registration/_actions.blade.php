@php
    /*
     * The wizard action bar — ONE definition for all five steps, so the
     * buttons are in the same order with the same wording everywhere.
     *
     * "Save draft" submits the same step form to the draft endpoint, which
     * validates leniently and keeps the applicant on the current step. Nothing
     * is ever discarded: leaving the wizard and coming back resumes this draft.
     */
    $draftRoute = route('students.registration.step.draft', ['step' => $step]);
    $nextLabel = (int) $step === 4 ? 'Continue to review' : 'Save & continue';
    $submitMode = (bool) ($submitMode ?? false);
@endphp

<div class="erp-wizard__footer">
    <div class="erp-wizard__footer-group">
        <button class="erp-btn erp-btn--ghost" type="submit" form="erp-cancel-form">
            Cancel registration
        </button>
        <p class="erp-wizard__hint">Your draft is kept until you cancel or submit.</p>
    </div>

    <div class="erp-wizard__footer-group">
        @if((int) $step > 1)
            <a class="erp-btn erp-btn--ghost" href="{{ route('students.registration.step', ['step' => (int) $step - 1]) }}">
                Previous
            </a>
        @endif

        @if($submitMode)
            <button class="erp-btn" type="submit" form="erp-submit-form" data-erp-save-intent>
                Submit for Approval
            </button>
        @else
            <button class="erp-btn erp-btn--ghost" type="submit"
                    form="erp-step-form" formaction="{{ $draftRoute }}" formnovalidate
                    data-erp-save-intent>
                Save draft
            </button>

            <button class="erp-btn" type="submit" form="erp-step-form" data-erp-save-intent>
                {{ $nextLabel }}
            </button>
        @endif
    </div>
</div>
