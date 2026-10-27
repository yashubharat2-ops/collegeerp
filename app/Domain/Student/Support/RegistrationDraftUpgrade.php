<?php

namespace App\Domain\Student\Support;

/**
 * Reads a registration draft payload written by an OLDER wizard shape.
 *
 * The wizard used to have five steps (Basic, Parent/Guardian, Academic,
 * Documents, Review). It now has four: Parent/Guardian was merged INTO Basic
 * Information, so every later step moved down by one.
 *
 * A stored payload therefore cannot simply be read with the new step numbers —
 * step 2 used to hold parent/guardian data and now holds the academic record.
 * This class holds the ONE old → new mapping, and both the data migration and
 * the runtime accessors use it, so a draft is never reinterpreted twice or in
 * two different ways.
 *
 * Mapping (five-step payload → four-step payload):
 *
 *   1 Basic            → 1 Basic (Personal + …, merged with the old 2)
 *   2 Parent/Guardian  → 1 Basic (merged into it; the keys are disjoint)
 *   3 Academic         → 2 Academic
 *   4 Documents        → 3 Documents
 *   5 Review           → 4 Review
 *
 * `current_step` follows the same map: a draft parked on the old step 2 resumes
 * on the new step 1, because that is where its parent data now lives.
 */
final class RegistrationDraftUpgrade
{
    /** The wizard shape this class produces: the four-step payload. */
    public const VERSION = 4;

    /** Old step → new step. */
    private const STEP_MAP = [
        1 => 1,
        2 => 1,
        3 => 2,
        4 => 3,
        5 => 4,
    ];

    /**
     * Keys that can only have come from the legacy step 2 (Parent/Guardian).
     * Used to recognise an old payload without trusting a version marker.
     */
    private const PARENT_KEYS = [
        'father_name', 'father_phone', 'father_email', 'father_occupation',
        'mother_name', 'mother_phone', 'mother_email', 'mother_occupation',
        'guardian_relation', 'guardian_name', 'guardian_phone', 'guardian_email',
        'guardian_occupation', 'guardian_address',
    ];

    /** Keys of the old step 3 (Academic Record). */
    private const ACADEMIC_KEYS = [
        'academic_year_id', 'program_id', 'section_id', 'semester',
        'enrollment_date', 'admission_date', 'qualifications',
    ];

    /**
     * Whether this stored payload was produced by the five-step wizard.
     *
     * Detection is content-based (never a blind "if it has key 2" guess):
     *  - a key 5 can only exist in the old shape (documents are not stored in
     *    the payload, so the new shape never reaches step 5);
     *  - a key 2 holding PARENT fields can only be the old parent step (in the
     *    new shape step 2 is the academic record);
     *  - a key 3 holding ACADEMIC fields can only be the old academic step (in
     *    the new shape step 3 is the document step, which stores nothing).
     *
     * Those three are the only unambiguous signals. In particular the REVIEW
     * step must NOT be used: `remarks` is written by the new step 4 as well, so
     * treating "key 4 has remarks" as legacy would silently re-map a perfectly
     * current four-step payload (step 2 → 1, 3 → 2, 4 → 3) and destroy it.
     *
     * A draft that only ever saved step 1 (personal details) is byte-identical
     * in both shapes and is therefore not "legacy" — nothing needs moving.
     */
    public static function isLegacyPayload(array $payload): bool
    {
        if (array_key_exists(5, $payload)) {
            return true;
        }

        if (self::hasAny($payload[2] ?? null, self::PARENT_KEYS)) {
            return true;
        }

        return self::hasAny($payload[3] ?? null, self::ACADEMIC_KEYS);
    }

    /**
     * Translate a five-step payload into the four-step shape.
     *
     * Keys outside the mapping are dropped rather than guessed at. The merge
     * into step 1 is a plain union: basic and parent fields are disjoint, and
     * the old parent row wins only where a key exists in both (it cannot).
     *
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function upgradePayload(array $payload): array
    {
        if (! self::isLegacyPayload($payload)) {
            return self::onlyArrays($payload);
        }

        $upgraded = [
            RegistrationStepRules::STEP_BASIC => [],
            RegistrationStepRules::STEP_ACADEMIC => [],
            RegistrationStepRules::STEP_DOCUMENTS => [],
            RegistrationStepRules::STEP_REVIEW => [],
        ];

        foreach (self::STEP_MAP as $oldStep => $newStep) {
            $data = $payload[$oldStep] ?? $payload[(string) $oldStep] ?? null;

            if (! is_array($data)) {
                continue;
            }

            // Basic + Parent merge into one step; later steps map one-to-one.
            $upgraded[$newStep] = array_merge($upgraded[$newStep], $data);
        }

        return $upgraded;
    }

    /**
     * Old `current_step` → the step it corresponds to in the new wizard.
     *
     * ONLY for a payload {@see isLegacyPayload()} accepts: step 2 of the old
     * shape was Parent/Guardian, so mapping it is not the identity. Running a
     * CURRENT step number through this method would move a draft parked on step
     * 2 back to step 1 (and one on step 4 back to step 3) — see resumeStep().
     */
    public static function upgradeStep(int $step): int
    {
        return self::STEP_MAP[$step] ?? self::clampStep($step);
    }

    /**
     * The step a stored draft should resume on.
     *
     * A CURRENT four-step payload is used as stored (clamped to 1–4). A LEGACY
     * five-step payload is translated through {@see upgradeStep()} and, for the
     * academic step, corrected from what the payload actually holds: a draft
     * whose old step number says 3 but which never stored an academic record
     * resumes on step 1 rather than on an empty screen.
     *
     * @param  array<int|string, mixed>  $payload
     */
    public static function resumeStep(array $payload, int $currentStep): int
    {
        // A payload that is already in the four-step shape speaks the SAME step
        // vocabulary as the wizard, so its stored step is authoritative and only
        // needs clamping. Applying the five-step map here would drag a draft on
        // step 2 back to step 1 (and one on step 4 back to step 3), i.e. resume
        // the wrong page for every current draft.
        if (! self::isLegacyPayload($payload)) {
            return self::clampStep($currentStep);
        }

        $step = self::upgradeStep($currentStep);

        $upgraded = self::upgradePayload($payload);

        // A stored academic record means the applicant reached step 2 for real.
        if (RegistrationStepRules::STEP_ACADEMIC === $step && $upgraded[RegistrationStepRules::STEP_ACADEMIC] === []) {
            return RegistrationStepRules::STEP_BASIC;
        }

        return $step;
    }

    /** Keep a step number inside the wizard's own range. */
    private static function clampStep(int $step): int
    {
        return min(max($step, RegistrationStepRules::STEP_BASIC), RegistrationStepRules::STEP_REVIEW);
    }

    /** @param  mixed  $value */
    private static function hasAny($value, array $keys): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the array-valued step buckets (defensive against a payload that
     * was hand-edited or truncated).
     *
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private static function onlyArrays(array $payload): array
    {
        $clean = [];

        foreach ($payload as $step => $data) {
            if (is_array($data) && (int) $step >= 1 && (int) $step <= RegistrationStepRules::STEP_REVIEW) {
                $clean[(int) $step] = $data;
            }
        }

        return $clean;
    }
}
