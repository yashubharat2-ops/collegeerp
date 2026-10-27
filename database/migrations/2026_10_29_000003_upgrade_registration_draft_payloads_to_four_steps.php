<?php

use App\Domain\Student\Support\RegistrationDraftUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrates drafts written by the five-step wizard to the four-step layout.
 *
 * The mapping is NOT "step N becomes step N-1": Parent/Guardian (old step 2) was
 * merged INTO Basic Information (old step 1), so the payload is regrouped and
 * then the later steps shift down:
 *
 *   old 1 Basic            → new 1 Basic  (keeps its own keys)
 *   old 2 Parent/Guardian  → new 1 Basic  (merged in; the key sets are disjoint)
 *   old 3 Academic         → new 2 Academic
 *   old 4 Documents        → new 3 Documents
 *   old 5 Review           → new 4 Review
 *
 * `current_step` follows the same map, so an applicant who was parked on the
 * Parent/Guardian page reopens Basic Information — the page their parent data
 * now lives on — instead of landing on the Academic Record by accident.
 *
 * The mapping itself lives in RegistrationDraftUpgrade, which the model also
 * uses at read time, so a draft that was written before this migration and one
 * written after it can never be interpreted two different ways.
 *
 * Nothing is deleted: only the payload JSON and the step marker are rewritten,
 * and already-upgraded drafts are left untouched (the upgrade is idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('student_registration_drafts')
            ->select(['id', 'payload', 'current_step'])
            ->orderBy('id')
            ->chunk(200, function ($drafts): void {
                foreach ($drafts as $draft) {
                    $payload = json_decode((string) $draft->payload, true);

                    if (! is_array($payload) || ! RegistrationDraftUpgrade::isLegacyPayload($payload)) {
                        continue;
                    }

                    DB::table('student_registration_drafts')
                        ->where('id', $draft->id)
                        ->update([
                            'payload' => json_encode(RegistrationDraftUpgrade::upgradePayload($payload)),
                            'current_step' => RegistrationDraftUpgrade::upgradeStep((int) $draft->current_step),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Deliberately irreversible: re-splitting a merged step could not tell
        // basic fields from parent fields any more, and inventing a "parent"
        // step back into a payload that no longer distinguishes them would risk
        // moving personal data onto the wrong page. The forward migration is
        // additive (no column or row is removed), so nothing is lost by keeping
        // the new shape.
    }
};
