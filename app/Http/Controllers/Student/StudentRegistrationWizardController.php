<?php

namespace App\Http\Controllers\Student;

use App\Domain\Student\Services\AcademicContextService;
use App\Domain\Student\Services\CourseDocumentRequirementService;
use App\Domain\Student\Services\GeoLocationService;
use App\Domain\Student\Services\RegistrationDocumentStagingService;
use App\Domain\Student\Services\RegistrationDraftService;
use App\Domain\Student\Services\RegistrationSubmissionService;
use App\Domain\Student\Services\StudentDocumentService;
use App\Domain\Student\Services\StudentIdentifierService;
use App\Domain\Student\Services\StudentPhotoService;
use App\Domain\Student\Support\Aadhaar;
use App\Domain\Student\Support\RegistrationDuplicateDetector;
use App\Domain\Student\Support\RegistrationIdentityGuard;
use App\Domain\Student\Support\RegistrationStepRules;
use App\Domain\Student\Support\SensitiveIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\Registration\SaveRegistrationStepRequest;
use App\Http\Requests\Student\Registration\SubmitRegistrationRequest;
use App\Http\Requests\Student\Registration\UploadRegistrationDocumentRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AdmissionDocumentType;
use App\Models\GeoLocation;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentQualification;
use App\Models\StudentRegistrationDraft;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The four-step student registration wizard.
 *
 * Replaces the single long registration form with:
 *  1. Basic Information → 2. Parent/Guardian → 3. Academic Record →
 *  4. Attach Documents → 5. Final Review → Submit for Approval.
 *
 * Everything the applicant enters is validated server-side on the step that
 * owns it, stored in a per-user draft (no student row exists yet), and finally
 * submitted through the SAME StudentRegistrationService the previous flow used —
 * one pending student, one approval request, documents in the existing
 * Documents module, and no enrollment until an approver decides.
 *
 * Authorization: every action requires `students.create`; every draft lookup is
 * scoped to the current college AND the current user, so a draft id from another
 * user — or another tenant — is a 404 rather than a read.
 */
class StudentRegistrationWizardController extends Controller
{
    /** Flash key: the step a rejected no-draft save belonged to. */
    private const FAILED_STEP_KEY = 'registration_failed_step';

    public function __construct(
        private readonly RegistrationDraftService $drafts,
        private readonly RegistrationSubmissionService $submission,
        private readonly RegistrationDocumentStagingService $staging,
        private readonly GeoLocationService $geo,
        private readonly CourseDocumentRequirementService $requirements,
        private readonly StudentPhotoService $photos,
        private readonly StudentIdentifierService $identifiers,
        private readonly AcademicContextService $academic,
    ) {}

    /** GET students/registration — resume the live draft, or start one. */
    public function start(Request $request): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $collegeId = (int) app(TenantContext::class)->id();
        $userId = (int) $request->user()->getKey();

        $draft = $this->drafts->startOrResume($collegeId, $userId);

        // Resume where the draft actually is — a "Save draft" keeps the marker on
        // the step being edited, "Save & continue" advances it. Never a fixed 1.
        $step = $draft->currentStep();

        // A rejected save with no draft behind it is answered with a redirect to
        // the step that failed; that step has nothing to render yet, so it
        // forwards the applicant here and the draft is created now. Keeping the
        // flash across this one hop is what makes the failure still visible, and
        // the recorded step reopens the form that actually failed.
        if (session()->has('errors')) {
            session()->keep(['errors', '_old_input', self::FAILED_STEP_KEY]);

            $failedStep = (int) session()->get(self::FAILED_STEP_KEY, $step);

            if ($failedStep >= 1 && $failedStep <= StudentRegistrationDraft::LAST_STEP) {
                $step = $failedStep;
            }
        }

        return redirect()->route('students.registration.step', ['step' => $step]);
    }

    /** GET students/registration/step/{step} */
    public function show(Request $request, int $step): View|RedirectResponse
    {
        $this->authorize('create', Student::class);

        if ($step < 1 || $step > StudentRegistrationDraft::LAST_STEP) {
            abort(404, 'Unknown registration step.');
        }

        $collegeId = (int) app(TenantContext::class)->id();
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            // A read never creates a draft. Only the entry point starts one, for
            // the authenticated user, in their own college — so a bare step URL
            // (a stale page, a bookmark, another applicant's link) cannot open a
            // wizard as a side effect of a GET. A supplied draft_id that is not
            // this user's own never reaches this point: resolveDraft() already
            // answered 404, so no foreign draft is read and no replacement draft
            // appears in its place.
            //
            // The forwarded request is the one a rejected save lands on, so the
            // flashed failure travels with it (see start()). Landing here with a
            // flashed failure also identifies THIS as the step that failed.
            if (session()->has('errors')) {
                session()->flash(self::FAILED_STEP_KEY, $step);
            }

            session()->keep(['errors', '_old_input', self::FAILED_STEP_KEY]);

            return redirect()->route('students.registration.create');
        }

        if ($draft->isSubmitted()) {
            return redirect()
                ->route('students.index')
                ->with('success', 'This registration has already been submitted for approval.');
        }

        $merged = $draft->mergedData();

        return view('students.registration.wizard', array_merge([
            'draft' => $draft,
            'step' => $step,
            'steps' => RegistrationStepRules::STEPS,
            'labels' => StudentRegistrationDraft::STEPS,
            'merged' => $merged,
            'stepData' => $draft->stepData($step),
            'completed' => $this->completedSteps($draft),
            'draftId' => (int) $draft->id,
        ], $this->stepViewData($step, $draft, $merged, $collegeId)));
    }

    /**
     * POST students/registration/step/{step} — "Next".
     *
     * Validates the step strictly, then stores it and moves on. Field errors come
     * back to THIS step (the redirect target is always the step that failed).
     */
    public function saveStep(SaveRegistrationStepRequest $request, int $step): RedirectResponse
    {
        return $this->persistStep($request, $step, advance: true);
    }

    /** POST students/registration/step/{step}/draft — "Save draft". */
    public function saveDraft(SaveRegistrationStepRequest $request, int $step): RedirectResponse
    {
        return $this->persistStep($request, $step, advance: false);
    }

    /** POST students/registration/documents — attach one document. */
    public function uploadDocument(UploadRegistrationDocumentRequest $request): RedirectResponse
    {
        $collegeId = (int) app(TenantContext::class)->id();
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            return redirect()->route('students.registration.create');
        }

        $this->assertDraftEditable($draft);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->staging->store(
            $draft,
            $file,
            $request->safe()->only(['document_type_id', 'title', 'remarks']),
            $collegeId,
            (int) $request->user()->getKey(),
        );

        return redirect()
            ->route('students.registration.step', ['step' => RegistrationStepRules::STEP_DOCUMENTS])
            ->with('success', 'Document attached to this registration.');
    }

    /** POST students/registration/documents/{document} — replace the file. */
    public function replaceDocument(UploadRegistrationDocumentRequest $request, int $document): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $collegeId = (int) app(TenantContext::class)->id();
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            return redirect()->route('students.registration.create');
        }

        $this->assertDraftEditable($draft);

        $model = $this->drafts->findDocument($draft, $document);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->staging->replace(
            $model,
            $file,
            $request->safe()->only(['document_type_id', 'title', 'remarks']),
            $collegeId,
            (int) $request->user()->getKey(),
        );

        return redirect()
            ->route('students.registration.step', ['step' => RegistrationStepRules::STEP_DOCUMENTS])
            ->with('success', 'Document replaced. The new file is awaiting verification.');
    }

    /** POST students/registration/documents/{document}/delete */
    public function destroyDocument(Request $request, int $document): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            return redirect()->route('students.registration.create');
        }

        $this->assertDraftEditable($draft);

        $this->staging->delete($this->drafts->findDocument($draft, $document));

        return redirect()
            ->route('students.registration.step', ['step' => RegistrationStepRules::STEP_DOCUMENTS])
            ->with('success', 'Document removed from this registration.');
    }

    /**
     * GET students/registration/documents/{document}/download
     *
     * Streamed from the private disk through an authorized controller action, so
     * no public URL ever points at a stored file and no document path appears in
     * a link the browser could share.
     */
    public function downloadDocument(Request $request, int $document): StreamedResponse
    {
        $this->authorize('create', Student::class);

        $collegeId = (int) app(TenantContext::class)->id();
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            abort(404, 'Registration draft not found.');
        }

        $model = $this->drafts->findDocument($draft, $document);
        $path = $this->staging->path($model, $collegeId);

        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'The stored file could not be found.');
        }

        $name = StudentDocumentService::safeDownloadName($model->original_filename, 'document');

        return Storage::disk('private')->download($path, $name);
    }

    /**
     * GET students/registration/image/{field}
     *
     * Streams one of the step-1 images of THIS user's draft from the private
     * disk. Images are never served from a public path, and the field name is
     * whitelisted, so no arbitrary file can be read through this route.
     */
    public function image(Request $request, string $field): StreamedResponse
    {
        $this->authorize('create', Student::class);

        if (! array_key_exists($field, RegistrationStepRules::IMAGE_FIELDS)) {
            abort(404);
        }

        $collegeId = (int) app(TenantContext::class)->id();
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            abort(404);
        }

        $path = $draft->stepData(RegistrationStepRules::STEP_BASIC)[RegistrationStepRules::IMAGE_FIELDS[$field]['column']] ?? null;

        if (! is_string($path) || $path === '') {
            abort(404);
        }

        $path = StudentDocumentService::assertSafePath($path);

        if (! str_starts_with($path, 'students/'.$collegeId.'/') || ! Storage::disk('private')->exists($path)) {
            abort(404);
        }

        return Storage::disk('private')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** POST students/registration/cancel — discard the draft and its files. */
    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $draft = $this->resolveDraft($request);

        if ($draft !== null) {
            $this->drafts->discard($draft, (int) $request->user()->getKey(), $this->staging);
        }

        return redirect()
            ->route('students.index')
            ->with('success', 'Registration cancelled. Nothing was submitted.');
    }

    /** POST students/registration/submit — validate every step, then submit. */
    public function submit(SubmitRegistrationRequest $request): RedirectResponse
    {
        $collegeId = (int) app(TenantContext::class)->id();

        // Submitting never creates a draft: there is nothing to submit yet, so
        // the applicant is sent to the wizard entry point instead.
        $draft = $this->resolveDraft($request);

        if ($draft === null) {
            return redirect()->route('students.registration.create');
        }

        if ($draft->isSubmitted()) {
            return redirect()->route('students.index')->with(
                'success',
                'This registration was already submitted; no duplicate was created.',
            );
        }

        try {
            $result = $this->submission->submit(
                $draft,
                $request->validated(),
                $collegeId,
                (int) $request->user()->getKey(),
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // A pre-submit failure is reported on the step that owns the field,
            // so the applicant fixes it where it lives instead of hunting
            // through the review screen.
            $step = (int) ($exception->errors()['_step'][0] ?? RegistrationStepRules::STEP_REVIEW);
            $errors = $exception->errors();
            unset($errors['_step']);

            return redirect()
                ->route('students.registration.step', ['step' => $step])
                ->withInput()
                ->withErrors($errors);
        }

        $message = $result['created']
            ? 'Registration '.$result['student']->student_number.' submitted for approval.'
            : 'A pending registration already exists for this student; no duplicate was created.';

        return redirect()
            ->route('students.index')
            ->with('success', $message.' Approval request '.$result['approval_request']->reference_number.'.');
    }

    /**
     * GET students/registration/academic-options — dependent academic levels.
     *
     * Answers "what can be chosen under this academic year / programme?" for the
     * wizard's Current Course block, so the Semester/Year and Section selects
     * follow the selection instead of every dropdown listing the whole college.
     *
     * Read-only reference data (ids and labels only — no student is involved),
     * so a GET is safe. It goes through the same AcademicContextService the
     * write path uses, which means the browser can never be offered a
     * combination the server would then refuse.
     */
    public function academicOptions(Request $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $collegeId = (int) app(TenantContext::class)->id();

        $yearId = $request->query('academic_year_id');
        $programId = $request->query('program_id');

        $yearId = $yearId !== null && $yearId !== '' && ctype_digit((string) $yearId) ? (int) $yearId : null;
        $programId = $programId !== null && $programId !== '' && ctype_digit((string) $programId) ? (int) $programId : null;

        // A forged or foreign id resolves to "no rows" (the master tables carry
        // CollegeScope and every query filters the explicit college), never to
        // another tenant's structure and never to an error page.
        $options = $this->academic->options($yearId, $programId, $collegeId);

        return response()->json([
            'data' => $options,
            'terms_configured' => $options['terms'] !== [],
        ]);
    }

    /**
     * GET students/registration/geo/children — dependent address options.
     *
     * Read-only reference data (no applicant data is involved), so a GET is
     * safe; the response carries ids and names only.
     */
    public function geoChildren(Request $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $level = (string) $request->query('level', '');
        $parentId = $request->query('parent_id');

        if (! in_array($level, GeoLocation::LEVELS, true)) {
            return response()->json(['data' => []]);
        }

        $children = $this->geo->children($parentId !== null && $parentId !== '' ? (int) $parentId : null, $level)
            ->map(fn (GeoLocation $node) => ['id' => (int) $node->id, 'name' => $node->name])
            ->values();

        return response()->json(['data' => $children]);
    }

    // -----------------------------------------------------------------
    // Step persistence
    // -----------------------------------------------------------------

    /** Store one validated step, then continue to the next one (or stay). */
    private function persistStep(SaveRegistrationStepRequest $request, int $step, bool $advance): RedirectResponse
    {
        $collegeId = (int) app(TenantContext::class)->id();

        if ($step < 1 || $step > StudentRegistrationDraft::LAST_STEP) {
            abort(404, 'Unknown registration step.');
        }

        // The draft is deliberately NOT created here. Everything below can still
        // reject the request (duplicate identity, an address chain that does not
        // line up, a field error raised during normalisation), and a rejected
        // request must leave no trace at all — no draft row, no audit entry, no
        // stored file. The row is created only once the save is certain.
        $draft = $this->resolveDraft($request);

        if ($draft !== null && $draft->isSubmitted()) {
            return redirect()->route('students.index')->with(
                'success',
                'This registration has already been submitted for approval.',
            );
        }

        $data = $request->validated();
        $merged = $draft?->mergedData() ?? [];

        // Identity uniqueness is checked BEFORE anything is written — not just
        // before the row is saved, but before a single image is stored — so a
        // blocked registration cannot leave an orphaned file or payload behind.
        // A strict save of step 1 reports the duplicate while the applicant is
        // on the field.
        if ($step === RegistrationStepRules::STEP_BASIC && $request->isStrict()) {
            try {
                RegistrationIdentityGuard::assertAvailable($data + $merged, $collegeId);
            } catch (ValidationException $exception) {
                return $this->stepError($step, $exception, $draft);
            }
        }

        // Media are stored (or retained/removed) before the payload is saved.
        $pendingDeletes = [];
        $data = $this->stageImages($request, $data, $draft, $step, $collegeId, $pendingDeletes);

        try {
            $normalised = RegistrationStepRules::normalise($step, $data, $merged, $collegeId, $this->geo);
        } catch (ValidationException $exception) {
            // The dependent address chain is verified during normalisation; a
            // mismatch must come back as a field error on THIS step, and the
            // images stored a moment ago are removed again so the failed save
            // leaves nothing behind.
            foreach ($pendingDeletes as $path) {
                $this->photos->delete($path);
            }

            return $this->stepError($step, $exception, $draft);
        }

        // Step 3 → review: the types the selected course requires must be
        // DECLARED. Only "Continue to review" enforces this — "Save draft" stays
        // permissive so an applicant can leave the page and come back. The files
        // are never demanded here; a declared type with no file is a complete
        // answer (see CourseDocumentRequirementService::missingDeclared()).
        if ($step === RegistrationStepRules::STEP_DOCUMENTS && $request->isStrict()) {
            $missing = $this->requirements->missingDeclared(
                isset($merged['program_id']) ? (int) $merged['program_id'] : null,
                $collegeId,
                (array) ($normalised['declared_documents'] ?? []),
            );

            if ($missing->isNotEmpty()) {
                $names = $missing->map(fn ($type) => $type->name)->implode(', ');

                return $this->stepError($step, ValidationException::withMessages([
                    'documents' => 'Tick the document'
                        .($missing->count() === 1 ? '' : 's')
                        .' your course requires before continuing: '.$names.'.',
                ]), $draft);
            }
        }

        // Server-owned paths are re-attached after normalisation, because the
        // normalised payload is built from fields only.
        if ($step === RegistrationStepRules::STEP_BASIC) {
            // The plain identity numbers never reach the stored payload: only
            // the encrypted form (plus the Aadhaar tail) is carried over.
            unset($normalised['aadhaar_number'], $normalised['govt_id_number']);

            // The five media paths are server-generated, so they are carried
            // into the payload separately from the validated fields.
            $mediaColumns = array_flip(array_column(RegistrationStepRules::IMAGE_FIELDS, 'column'));

            $normalised = array_merge(
                $normalised,
                array_intersect_key($data, $mediaColumns),
                $this->encryptIdentity($data, $draft?->stepData(RegistrationStepRules::STEP_BASIC) ?? []),
            );
        }

        // Every rejection path has been passed: now the draft may exist. For a
        // returning applicant this is the existing row (nothing is duplicated);
        // for a first save it is created here, together with its audit entry.
        $draft ??= $this->drafts->startOrResume($collegeId, (int) $request->user()->getKey());

        $this->drafts->saveStep($draft, $step, $normalised, advance: $advance);

        // Possible duplicates (same name AND a corroborating detail) are hints,
        // never blocks. They are recomputed on every strict step-1 save so the
        // review screen shows what the approver will see, and the submit path
        // computes them again against the committed data.
        if ($step === RegistrationStepRules::STEP_BASIC && $request->isStrict()) {
            $warnings = RegistrationDuplicateDetector::toWarnings(
                RegistrationDuplicateDetector::possibleDuplicates($draft->mergedData(), $collegeId),
            );

            $draft->forceFill(['duplicate_warnings' => $warnings])->save();
        }

        // Old blobs are removed only after the draft has been saved.
        foreach ($pendingDeletes as $path) {
            $this->photos->delete($path);
        }

        $target = $advance
            ? min($step + 1, StudentRegistrationDraft::LAST_STEP)
            : $step;

        return redirect()
            ->route('students.registration.step', ['step' => $target])
            ->with('success', $advance
                ? RegistrationStepRules::STEPS[$step].' saved.'
                : 'Draft saved. You can continue later from this step.');
    }

    /**
     * Store the five step-1 images and/or apply removal flags.
     *
     * The stored path is carried forward when no new file is chosen, so saving
     * step 1 again (to change a phone number, say) never silently drops an
     * uploaded photograph. A replaced or removed file is queued for deletion and
     * deleted only after the draft has been saved successfully.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $pendingDeletes
     * @return array<string, mixed>
     */
    private function stageImages(
        Request $request,
        array $data,
        ?StudentRegistrationDraft $draft,
        int $step,
        int $collegeId,
        array &$pendingDeletes,
    ): array {
        if ($step !== RegistrationStepRules::STEP_BASIC) {
            return $data;
        }

        // No draft yet (a first save that has not been written): there is
        // nothing to retain from a previous attempt.
        $previous = $draft?->stepData(RegistrationStepRules::STEP_BASIC) ?? [];

        foreach (RegistrationStepRules::IMAGE_FIELDS as $field => $meta) {
            $column = $meta['column'];
            $file = $request->file($field);
            $remove = filter_var($data['remove_'.$field] ?? false, FILTER_VALIDATE_BOOLEAN);
            $existing = $previous[$column] ?? null;

            unset($data[$field], $data['remove_'.$field]);

            if ($file instanceof UploadedFile) {
                $data[$column] = $this->photos->store($file, $collegeId, $field, $meta['label']);

                if (is_string($existing) && $existing !== '') {
                    $pendingDeletes[] = $existing;
                }

                continue;
            }

            if ($remove) {
                $data[$column] = null;

                if (is_string($existing) && $existing !== '') {
                    $pendingDeletes[] = $existing;
                }

                continue;
            }

            $data[$column] = $existing;
        }

        return $data;
    }

    /**
     * Protect the identity numbers inside the draft payload.
     *
     * A draft lives in the database for days, so the Aadhaar and government ID
     * numbers are stored exactly as they would be on the student record: a
     * Crypt payload plus (for Aadhaar) the four-digit tail needed for display.
     * The plain value never appears in the JSON column.
     *
     * A blank field means "keep what is stored" — the same rule the Student
     * profile form uses — because the value is never echoed back into the page.
     * An explicit remove_* flag clears it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $previous  the step's previously stored payload
     * @return array<string, mixed>
     */
    private function encryptIdentity(array $data, array $previous = []): array
    {
        $carry = [];

        $digits = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        if ($digits !== null) {
            $carry['aadhaar_encrypted'] = Aadhaar::encrypt($digits);
            $carry['aadhaar_last4'] = Aadhaar::last4($digits);
        } elseif (! empty($data['remove_aadhaar'])) {
            $carry['aadhaar_encrypted'] = null;
            $carry['aadhaar_last4'] = null;
        } elseif (! empty($previous['aadhaar_encrypted'])) {
            $carry['aadhaar_encrypted'] = $previous['aadhaar_encrypted'];
            $carry['aadhaar_last4'] = $previous['aadhaar_last4'] ?? null;
        }

        $govtNumber = $data['govt_id_number'] ?? null;

        if (is_string($govtNumber) && trim($govtNumber) !== '') {
            $carry['govt_id_number_encrypted'] = SensitiveIdentity::encrypt($govtNumber);
        } elseif (! empty($data['remove_govt_id'])) {
            $carry['govt_id_number_encrypted'] = null;
        } elseif (! empty($previous['govt_id_number_encrypted'])) {
            $carry['govt_id_number_encrypted'] = $previous['govt_id_number_encrypted'];
        }

        return $carry;
    }

    // -----------------------------------------------------------------
    // View data
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function stepViewData(int $step, StudentRegistrationDraft $draft, array $merged, int $collegeId): array
    {
        if ($step === RegistrationStepRules::STEP_BASIC) {
            $addressLevels = [];

            foreach (GeoLocation::LEVELS as $level) {
                $addressLevels[$level] = $this->geo->hasLevel($level);
            }

            // The ids the five selects are rendered with. The service fills in
            // any missing ancestor from the directory (a resumed draft may hold
            // a stale/partial chain) and falls back to the default country, so
            // the state list is populated instead of showing an empty box.
            $geoSelection = $this->geo->selection($merged);

            return [
                'addressLevels' => $addressLevels,
                'geoSelection' => $geoSelection,
                'geoOptions' => [
                    'country' => $this->geo->options(GeoLocation::LEVEL_COUNTRY),
                    'state' => $this->geo->options(GeoLocation::LEVEL_STATE, $geoSelection['country_id']),
                    'district' => $this->geo->options(GeoLocation::LEVEL_DISTRICT, $geoSelection['state_id']),
                    'block' => $this->geo->options(GeoLocation::LEVEL_BLOCK, $geoSelection['district_id']),
                    'village' => $this->geo->options(GeoLocation::LEVEL_VILLAGE, $geoSelection['block_id']),
                ],
                'aadhaarMasked' => Aadhaar::mask($draft->stepData(1)['aadhaar_last4'] ?? null),
                'hasGovtId' => filled($draft->stepData(1)['govt_id_number_encrypted'] ?? null),
                // Section A of step 1: the three identifiers, read-only. Only
                // values the existing workflows have already allocated are
                // shown; the rest are reported as pending.
                'identifiers' => $this->identifiers->forDraft($draft, $collegeId),
                // Guardian derivation (section D), also on step 1 now.
                'derivedRelations' => Student::DERIVED_GUARDIAN_RELATIONS,
                'duplicateWarnings' => (array) ($draft->duplicate_warnings ?? []),
            ];
        }

        if ($step === RegistrationStepRules::STEP_ACADEMIC) {
            $yearId = isset($merged['academic_year_id']) ? (int) $merged['academic_year_id'] : null;
            $programId = isset($merged['program_id']) ? (int) $merged['program_id'] : null;

            // The dependent levels are resolved for the CURRENT selection, so a
            // resumed draft shows the semester and section lists that belong to
            // its own academic year and programme — not every row in the college.
            $terms = $this->academic->terms($yearId, $collegeId);

            return [
                'academicYears' => AcademicYear::query()->where('college_id', $collegeId)->orderBy('name')->get(),
                'programs' => Program::query()->where('college_id', $collegeId)->orderBy('name')->get(),
                'terms' => $terms,
                // A college that has not configured academic terms yet keeps a
                // free-text field (see the step view): the wizard must stay
                // usable before the academic structure exists, and the server
                // accepts free text only in exactly that case.
                'termsConfigured' => $terms->isNotEmpty(),
                'termLabel' => $this->academic->termLabel(
                    isset($merged['academic_term_id']) ? $terms->firstWhere('id', (int) $merged['academic_term_id']) : null,
                ),
                'sections' => $this->academic->sections($yearId, $programId, $collegeId),
                'qualificationLevels' => StudentQualification::LEVEL_LABELS,
                'qualificationResultStatuses' => StudentQualification::RESULT_STATUSES,
                // Read-only echo of the admission category captured on step 1:
                // one writable copy only, so the two screens cannot disagree.
                'admissionCategory' => Student::categoryLabel($merged['category'] ?? null),
            ];
        }

        if ($step === RegistrationStepRules::STEP_DOCUMENTS) {
            $documents = $draft->documents()->with('documentType')->orderBy('id')->get();

            // The DECLARATIONS are the applicant's own ticks, stored in the draft
            // payload; the files are separate rows. They are read separately here
            // so neither can be inferred from the other.
            $declared = array_values(array_map('intval', (array) ($merged['declared_documents'] ?? [])));

            return [
                'documentTypes' => AdmissionDocumentType::query()->where('college_id', $collegeId)->orderBy('name')->get(),
                'requirements' => $this->requirements->checklist(
                    isset($merged['program_id']) ? (int) $merged['program_id'] : null,
                    $collegeId,
                    $documents,
                    $declared,
                ),
                'declaredDocuments' => $declared,
                'documents' => $documents,
            ];
        }

        // Final review — grouped, read-only echo of every step.
        $documents = $draft->documents()->with('documentType')->orderBy('id')->get();

        $declared = array_values(array_map('intval', (array) ($merged['declared_documents'] ?? [])));

        return [
            'documents' => $documents,
            'declaredDocuments' => $declared,
            'requirements' => $this->requirements->checklist(
                isset($merged['program_id']) ? (int) $merged['program_id'] : null,
                $collegeId,
                $documents,
                $declared,
            ),
            'qualificationRows' => (array) ($merged['qualifications'] ?? []),
            'academicYear' => isset($merged['academic_year_id']) ? AcademicYear::query()->find($merged['academic_year_id']) : null,
            'program' => isset($merged['program_id']) ? Program::query()->find($merged['program_id']) : null,
            'section' => isset($merged['section_id']) ? Section::query()->find($merged['section_id']) : null,
            'academicTerm' => isset($merged['academic_term_id']) ? AcademicTerm::query()->find($merged['academic_term_id']) : null,
            'fullName' => RegistrationStepRules::fullName($merged),
            'aadhaarMasked' => Aadhaar::mask($draft->stepData(1)['aadhaar_last4'] ?? null),
            'identifiers' => $this->identifiers->forDraft($draft, $collegeId),
            'duplicateWarnings' => (array) ($draft->duplicate_warnings ?? []),
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The draft this request acts on, or null.
     *
     * A posted `draft_id` is resolved through the user-scoped lookup (so another
     * user's draft is a 404); otherwise the user's live draft is used.
     *
     * This method never creates a draft. Creating one is a deliberate step taken
     * only when a save is certain (see persistStep()), because a request that is
     * rejected — duplicate phone, an address chain that does not line up, an
     * invalid field — must not leave a draft row behind.
     */
    private function resolveDraft(Request $request): ?StudentRegistrationDraft
    {
        $collegeId = (int) app(TenantContext::class)->id();
        $userId = (int) $request->user()->getKey();

        $draftId = $request->input('draft_id');

        if ($draftId !== null && $draftId !== '') {
            return $this->drafts->findForUser((int) $draftId, $collegeId, $userId);
        }

        return $this->drafts->current($collegeId, $userId);
    }

    /**
     * Send a field-level failure back to the step that owns it.
     *
     * Always an explicit redirect to the step's own URL: relying on
     * `url()->previous()` would send the applicant to whatever page the browser
     * came from (a bare "/" on a first visit) and lose the wizard's context.
     */
    private function stepError(
        int $step,
        ValidationException $exception,
        ?StudentRegistrationDraft $draft = null,
    ): RedirectResponse {
        $response = redirect()
            ->route('students.registration.step', ['step' => $step])
            ->withInput()
            ->withErrors($exception->errors());

        if ($draft === null) {
            // Nothing was written, so this step cannot render itself: it will
            // forward to the entry point, which starts the draft. This marker
            // tells the entry point which step to reopen, so the applicant is
            // not dropped on step 1 with an error belonging to step 3.
            $response->with(self::FAILED_STEP_KEY, $step);
        }

        return $response;
    }

    private function assertDraftEditable(StudentRegistrationDraft $draft): void
    {
        if ($draft->isSubmitted()) {
            abort(403, 'This registration has already been submitted.');
        }
    }

    /**
     * Steps the applicant has already filled in — drives the progress
     * indicator. It reflects stored data, not navigation history, so going back
     * never "un-completes" a step.
     *
     * @return array<int, bool>
     */
    private function completedSteps(StudentRegistrationDraft $draft): array
    {
        $completed = [];

        for ($step = 1; $step <= StudentRegistrationDraft::LAST_STEP; $step++) {
            $data = $draft->stepData($step);

            $completed[$step] = match ($step) {
                // Step 1 now also carries the parent/guardian block, so either
                // half having data marks the step as started.
                RegistrationStepRules::STEP_BASIC => filled($data['first_name'] ?? null)
                    || filled($data['father_name'] ?? null)
                    || filled($data['mother_name'] ?? null),
                RegistrationStepRules::STEP_ACADEMIC => filled($data['academic_year_id'] ?? null)
                    && filled($data['program_id'] ?? null),
                // A declaration is progress in its own right: step 3 is about
                // stating what you have, and a file is optional.
                RegistrationStepRules::STEP_DOCUMENTS => $draft->documents()->exists()
                    || filled($data['declared_documents'] ?? null),
                default => false,
            };
        }

        return $completed;
    }
}
