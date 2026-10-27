<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Support\Aadhaar;
use App\Domain\Student\Support\RegistrationIdentityGuard;
use App\Domain\Student\Support\RegistrationStepRules;
use App\Domain\Student\Support\SensitiveIdentity;
use App\Models\Section;
use App\Models\StudentRegistrationDraft;
use App\Models\StudentRegistrationDraftDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns a five-step draft into a registration submission.
 *
 * Nothing is written to `students` before this point: the applicant's work lives
 * in the draft, so an abandoned form is inert. On submit the service
 *
 *  1. re-validates EVERY step in strict mode against the STORED draft (never
 *     against whatever the browser last posted) and reports the first failing
 *     step so the wizard can send the user back to it with field errors;
 *  2. checks the cross-step rules the individual steps cannot see — guardian
 *     consistency, course-wise required documents, qualification arithmetic and
 *     the academic-context hierarchy;
 *  3. hands one assembled payload to StudentRegistrationService, which creates
 *     the pending student, one approval request and adopts the staged files.
 *
 * Because everything runs inside StudentRegistrationService's transaction, a
 * failure at any point leaves no orphaned student, document, qualification or
 * approval record behind.
 */
final class RegistrationSubmissionService
{
    public function __construct(
        private readonly StudentRegistrationService $registration,
        private readonly RegistrationDraftService $drafts,
        private readonly CourseDocumentRequirementService $requirements,
    ) {}

    /**
     * @param  array<string, mixed>  $reviewData  validated step-5 input (final remarks)
     * @return array{student: \App\Models\Student, approval_request: \App\Models\ApprovalRequest, created: bool, draft: StudentRegistrationDraft}
     */
    public function submit(StudentRegistrationDraft $draft, array $reviewData, int $collegeId, int $actorId): array
    {
        if ($draft->isSubmitted()) {
            throw ValidationException::withMessages([
                'registration' => 'This registration has already been submitted for approval.',
            ]);
        }

        $merged = array_merge($draft->mergedData(), array_filter([
            'remarks' => $reviewData['remarks'] ?? null,
        ], fn ($value) => $value !== null));

        // The draft stores the identity numbers encrypted (and only their
        // masked tail in clear text). They are decrypted here, in memory, for
        // the single call that writes the Student — never rendered, logged or
        // put in the approval payload.
        $merged = $this->withDecryptedIdentity($merged);

        // --- 1. Per-step re-validation, in order ---------------------------
        for ($step = 1; $step <= 4; $step++) {
            $this->validateStep($draft, $step, $merged);
        }

        // --- 2. Cross-step rules ------------------------------------------
        // Identity uniqueness is checked again here against the decrypted
        // numbers, because the wizard deliberately stores them encrypted and a
        // duplicate may have appeared while the draft was open. The failure
        // belongs to step 1, where the fields live.
        try {
            RegistrationIdentityGuard::assertAvailable($merged, $collegeId);
        } catch (ValidationException $exception) {
            throw $this->stepFailure(1, $exception->errors());
        }

        $this->assertGuardianConsistency($merged);
        $this->assertQualifications($merged);
        $this->assertAcademicContext($merged, $collegeId);
        $this->assertDocumentRequirements($draft, $merged, $collegeId);

        // --- 3. Assemble and submit ---------------------------------------
        $payload = array_merge($merged, ['remarks' => $merged['remarks'] ?? null]);

        $documents = $draft->documents()->get()->all();

        $result = $this->registration->register(
            $payload,
            [],
            $collegeId,
            $actorId,
            array_values((array) ($merged['qualifications'] ?? [])),
            $documents,
            replaceQualifications: true,
        );

        $this->drafts->markSubmitted($draft, (int) $result['student']->getKey(), (int) $result['approval_request']->getKey());

        return $result + ['draft' => $draft->refresh()];
    }

    /**
     * Replace the draft's encrypted identity columns with their plain values for
     * the write path, and drop the ciphertext keys from the payload.
     *
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private function withDecryptedIdentity(array $merged): array
    {
        $aadhaar = Aadhaar::decrypt($merged['aadhaar_encrypted'] ?? null);

        if ($aadhaar !== null) {
            $merged['aadhaar_number'] = $aadhaar;
        }

        $govtId = SensitiveIdentity::decrypt($merged['govt_id_number_encrypted'] ?? null);

        if ($govtId !== null) {
            $merged['govt_id_number'] = $govtId;
        }

        unset(
            $merged['aadhaar_encrypted'],
            $merged['aadhaar_last4'],
            $merged['govt_id_number_encrypted'],
            $merged['remove_aadhaar'],
            $merged['remove_govt_id'],
        );

        return $merged;
    }

    /**
     * Validate one stored step in strict mode.
     *
     * A failure is reported with a `_step` key so the controller can redirect to
     * the step that owns the offending field instead of dumping every error on
     * the review screen.
     *
     * @param  array<string, mixed>  $merged
     */
    private function validateStep(StudentRegistrationDraft $draft, int $step, array $merged): void
    {
        $data = $step === 5 ? $merged : array_merge($merged, $draft->stepData($step));

        $rules = RegistrationStepRules::rules($step, true, (int) $draft->college_id);

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($data, $rules, RegistrationStepRules::messages(), RegistrationStepRules::attributes());

        if ($validator->fails()) {
            throw $this->stepFailure($step, $validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function stepFailure(int $step, array $errors): ValidationException
    {
        $errors['_step'] = [(string) $step];

        return ValidationException::withMessages($errors);
    }

    /**
     * The guardian relation must be backed by the person it names.
     *
     * This is a consistency rule, not a new mandatory field: with no relation
     * chosen nothing is demanded, but choosing "Father" while the father's name
     * is empty (or "Other" with no guardian name at all) would store a guardian
     * block that identifies nobody.
     *
     * @param  array<string, mixed>  $merged
     */
    private function assertGuardianConsistency(array $merged): void
    {
        $relation = $merged['guardian_relation'] ?? null;

        $missing = match ($relation) {
            'father' => blank($merged['father_name'] ?? null) ? 'father_name' : null,
            'mother' => blank($merged['mother_name'] ?? null) ? 'mother_name' : null,
            'guardian', 'other' => blank($merged['guardian_name'] ?? null) ? 'guardian_name' : null,
            default => null,
        };

        if ($missing !== null) {
            throw $this->stepFailure(2, [
                $missing => [$relation === 'father'
                    ? 'Enter the father\'s name, or choose a different guardian.'
                    : ($relation === 'mother'
                        ? 'Enter the mother\'s name, or choose a different guardian.'
                        : 'Enter the guardian\'s name for the selected relation.')],
            ]);
        }
    }

    /**
     * Qualification rows that the per-row rules cannot decide on their own.
     *
     * - a row must name its level: "Class 12, 2024, 88%" with no level cannot
     *   be placed in a student's history;
     * - a marks pair must be coherent (obtained <= maximum) before a percentage
     *   can be derived from it;
     * - the percentage is NOT validated against the marks because a grade-based
     *   result legitimately has none (the service discards it in that case).
     *
     * @param  array<string, mixed>  $merged
     */
    private function assertQualifications(array $merged): void
    {
        $errors = [];

        foreach ((array) ($merged['qualifications'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $hasContent = filled($row['level'] ?? null)
                || filled($row['qualification'] ?? null)
                || filled($row['examination_year'] ?? null)
                || filled($row['board_university'] ?? null)
                || filled($row['institution'] ?? null)
                || filled($row['marks_obtained'] ?? null)
                || filled($row['grade'] ?? null);

            if (! $hasContent) {
                continue;
            }

            if (blank($row['level'] ?? null)) {
                $errors['qualifications.'.$index.'.level'] = ['Choose the qualification level (Class 10, Class 12, Graduation, Post Graduation or Other).'];
            }

            $obtained = $row['marks_obtained'] ?? null;
            $max = $row['max_marks'] ?? null;

            if ($obtained !== null && $obtained !== '' && ($max === null || $max === '')) {
                $errors['qualifications.'.$index.'.max_marks'] = ['Enter the maximum marks for this result.'];
            } elseif (is_numeric($obtained) && is_numeric($max) && (float) $max > 0 && (float) $obtained > (float) $max) {
                $errors['qualifications.'.$index.'.marks_obtained'] = ['Marks obtained cannot be more than the maximum marks.'];
            }

            if (is_numeric($max) && (float) $max <= 0) {
                $errors['qualifications.'.$index.'.max_marks'] = ['Maximum marks must be greater than zero.'];
            }
        }

        if ($errors !== []) {
            throw $this->stepFailure(3, $errors);
        }
    }

    /**
     * The enrollment context must describe a real combination inside this
     * college: a section belongs to exactly one academic year + programme pair.
     *
     * @param  array<string, mixed>  $merged
     */
    private function assertAcademicContext(array $merged, int $collegeId): void
    {
        $sectionId = $merged['section_id'] ?? null;

        if (blank($sectionId)) {
            return;
        }

        $section = Section::query()
            ->where('college_id', $collegeId)
            ->whereKey((int) $sectionId)
            ->first();

        if ($section === null) {
            throw $this->stepFailure(3, ['section_id' => ['Choose a section that belongs to this college.']]);
        }

        if ((string) $section->academic_year_id !== (string) ($merged['academic_year_id'] ?? '')) {
            throw $this->stepFailure(3, ['section_id' => ['The selected section does not belong to the selected academic year.']]);
        }

        if (filled($merged['program_id'] ?? null) && (string) $section->program_id !== (string) $merged['program_id']) {
            throw $this->stepFailure(3, ['section_id' => ['The selected section does not belong to the selected programme.']]);
        }
    }

    /**
     * Course-wise required documents.
     *
     * Requirements come from `admission_document_type_program` (falling back to
     * the document master's own `is_required`), and a document that the course
     * allows to be submitted later does not block the submission. Uploading is
     * never treated as verification.
     *
     * @param  array<string, mixed>  $merged
     */
    private function assertDocumentRequirements(StudentRegistrationDraft $draft, array $merged, int $collegeId): void
    {
        /** @var \Illuminate\Support\Collection<int, StudentRegistrationDraftDocument> $documents */
        $documents = $draft->documents()->get();

        $missing = $this->requirements->missingRequired(
            isset($merged['program_id']) ? (int) $merged['program_id'] : null,
            $collegeId,
            $documents->pluck('document_type_id')->filter()->map(fn ($id) => (int) $id)->all(),
        );

        if ($missing->isEmpty()) {
            return;
        }

        $names = $missing->map(fn ($type) => $type->name)->implode(', ');

        throw $this->stepFailure(4, [
            'documents' => ['Attach the documents required for the selected course: '.$names.'.'],
        ]);
    }
}
