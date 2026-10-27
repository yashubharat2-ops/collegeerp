<?php

namespace App\Domain\Student\Services;

use App\Models\StudentRegistrationDraft;
use App\Models\StudentRegistrationDraftDocument;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The persisted working copy behind the four-step registration wizard.
 *
 * A draft is owned by (college, user) and holds one JSON payload per step. It
 * is NOT a student — no `students` row exists until the final submit — which is
 * what makes "Save draft" safe: an abandoned registration can never appear in
 * the student list, block a number, or create an enrollment.
 *
 * Every read and write is scoped to the owning user inside the current college,
 * on top of the model's CollegeScope, so one applicant can neither see nor
 * advance somebody else's draft even inside the same college.
 */
class RegistrationDraftService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /** The live (not yet submitted) draft of this user, if any. */
    public function current(int $collegeId, int $userId): ?StudentRegistrationDraft
    {
        return StudentRegistrationDraft::query()
            ->where('college_id', $collegeId)
            ->where('user_id', $userId)
            ->where('status', StudentRegistrationDraft::STATUS_DRAFT)
            ->latest('id')
            ->first();
    }

    /**
     * Resume this user's draft, or begin one.
     *
     * Creating is idempotent per (college, user, draft) so a refresh on step 1
     * does not accumulate empty rows.
     */
    public function startOrResume(int $collegeId, int $userId): StudentRegistrationDraft
    {
        $draft = $this->current($collegeId, $userId);

        if ($draft !== null) {
            return $draft;
        }

        $draft = StudentRegistrationDraft::create([
            'college_id' => $collegeId,
            'user_id' => $userId,
            'status' => StudentRegistrationDraft::STATUS_DRAFT,
            'current_step' => 1,
            'payload' => [],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->audit->record('student.registration_draft_started', $draft, [], [
            'draft_id' => $draft->id,
            'user_id' => $userId,
        ]);

        return $draft;
    }

    /** A draft this user may act on, or a 404 (never another user's draft). */
    public function findForUser(int $draftId, int $collegeId, int $userId): StudentRegistrationDraft
    {
        $draft = StudentRegistrationDraft::query()
            ->where('college_id', $collegeId)
            ->where('user_id', $userId)
            ->whereKey($draftId)
            ->first();

        if ($draft === null) {
            abort(404, 'Registration draft not found.');
        }

        return $draft;
    }

    /**
     * Merge one validated step into the draft.
     *
     * The merge is a shallow merge PER STEP, so a field left out of a later save
     * of the same step is removed (that is what clearing an input means), while
     * the other steps are untouched. This is why the wizard can move back and
     * forth without losing anything.
     *
     * `$advance` is the difference between the two buttons:
     *  - "Save draft" (false) keeps `current_step` where the applicant actually
     *    is, so resuming reopens THAT step — saving step 1 as a draft must not
     *    pretend the applicant already reached step 2;
     *  - "Save & continue" (true) moves the marker to the next step.
     *
     * Either way the marker never moves backwards: a user who goes back from
     * the review to fix step 1 and saves a draft still resumes at the review.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveStep(
        StudentRegistrationDraft $draft,
        int $step,
        array $data,
        bool $advance = true,
    ): StudentRegistrationDraft
    {
        if ($draft->isSubmitted()) {
            throw ValidationException::withMessages([
                'draft' => 'This registration has already been submitted for approval and can no longer be edited.',
            ]);
        }

        if ($step < 1 || $step > StudentRegistrationDraft::LAST_STEP) {
            abort(404, 'Unknown registration step.');
        }

        // Read AND write through the current payload shape. A draft still
        // holding the old five-step JSON is translated once here (Parent/Guardian
        // merged into step 1, later steps shifted down), so the next write can
        // never land on a bucket an older step number used to own.
        $payload = $draft->upgradedPayload();
        $payload[$step] = $this->withoutNullBytes($data);

        // The step the draft is genuinely on: the next one after a "continue",
        // otherwise the one being saved. Never backwards.
        $targetStep = $advance
            ? min($step + 1, StudentRegistrationDraft::LAST_STEP)
            : $step;

        $currentStep = max($draft->currentStep(), $targetStep);

        $draft->update([
            'payload' => $payload,
            'current_step' => $currentStep,
            'updated_by' => $draft->user_id,
        ]);

        return $draft->refresh();
    }

    /** Remove the draft and its staged files (Cancel). */
    public function discard(StudentRegistrationDraft $draft, int $userId, RegistrationDocumentStagingService $staging): void
    {
        if ($draft->isSubmitted()) {
            return;
        }

        DB::transaction(function () use ($draft, $staging): void {
            foreach ($draft->documents()->get() as $document) {
                $staging->delete($document);
            }

            $snapshot = ['draft_id' => $draft->id, 'current_step' => $draft->current_step];
            $draft->delete();

            $this->audit->record('student.registration_draft_cancelled', null, $snapshot, []);
        });
    }

    /** Mark the draft as submitted against the student it created. */
    public function markSubmitted(StudentRegistrationDraft $draft, int $studentId, int $approvalRequestId): StudentRegistrationDraft
    {
        $draft->update([
            'status' => StudentRegistrationDraft::STATUS_SUBMITTED,
            'current_step' => StudentRegistrationDraft::LAST_STEP,
            'student_id' => $studentId,
            'approval_request_id' => $approvalRequestId,
            'submitted_at' => now(),
            'updated_by' => $draft->user_id,
        ]);

        return $draft->refresh();
    }

    /**
     * Strip control characters that have no business in a stored payload and
     * would make the JSON column unreadable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutNullBytes(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $clean[$key] = is_string($value) ? str_replace("\0", '', $value) : $value;
        }

        return $clean;
    }

    /** Staged (not yet adopted) documents of the draft. */
    public function documents(StudentRegistrationDraft $draft)
    {
        return $draft->documents()->with('documentType')->orderBy('id')->get();
    }

    /** Staged document belonging to this draft, or 404. */
    public function findDocument(StudentRegistrationDraft $draft, int $documentId): StudentRegistrationDraftDocument
    {
        $document = $draft->documents()->whereKey($documentId)->first();

        if ($document === null) {
            abort(404, 'Document not found for this registration draft.');
        }

        return $document;
    }
}
