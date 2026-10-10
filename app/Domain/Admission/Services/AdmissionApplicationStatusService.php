<?php

namespace App\Domain\Admission\Services;

use App\Models\AdmissionApplication;

/**
 * The one place that decides whether an application may change status, and what
 * else must be written with that change.
 *
 * Used by the single-record update (AdmissionApplicationController) and by the
 * bulk review action, so both enforce the same workflow map
 * ({@see AdmissionApplicationWorkflow::canTransition()}) and the same
 * server-side `submitted_at` stamp. Keeping this in one service means a bulk
 * change can never follow a rule the single edit does not follow.
 *
 * The service does not write. Callers persist the attributes it returns, and
 * must hold a row lock on the application when the status is read and written
 * from separate statements (the bulk path does).
 */
final class AdmissionApplicationStatusService
{
    public function canTransition(string $from, string $to): bool
    {
        return AdmissionApplicationWorkflow::canTransition($from, $to);
    }

    /**
     * @return list<string>
     */
    public function allowedFrom(string $from): array
    {
        return AdmissionApplicationWorkflow::allowedFrom($from);
    }

    /**
     * The attributes to persist for a change to $newStatus.
     *
     * Adds the server-side `submitted_at` stamp: the first transition out of
     * draft records when the application was submitted. The timestamp is never
     * taken from the browser, and an existing stamp is never cleared.
     *
     * Caller must already have checked {@see canTransition()}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributesForStatus(AdmissionApplication $application, string $newStatus, array $data = []): array
    {
        $data['status'] = $newStatus;

        if ($newStatus !== 'draft' && $application->submitted_at === null) {
            $data['submitted_at'] = now();
        }

        return $data;
    }
}
