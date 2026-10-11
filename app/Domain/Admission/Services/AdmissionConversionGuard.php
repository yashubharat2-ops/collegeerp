<?php

namespace App\Domain\Admission\Services;

use App\Models\Admission;
use Illuminate\Validation\ValidationException;

/**
 * The business rule that BOTH admission → student conversion paths share:
 *
 *   A cancelled admission never produces a student, whichever route is used.
 *
 * It lives here, in the domain layer, rather than in a controller or a Blade
 * view, because the application route (`students.convert`) is a plain POST that
 * can be called directly. The application's own status is NOT enough to enforce
 * it: cancelling an admission deliberately leaves its AdmissionApplication
 * untouched, so an application can still read "admitted" after its admission was
 * cancelled. The admission itself is the source of truth for the cancellation.
 *
 * Callers must hold the AdmissionApplication row lock (see
 * {@see AdmissionConversionLock}). The admission cancel path takes the same lock
 * before it writes the cancelled status, so the check below can never interleave
 * with a cancellation of the same application.
 *
 * Tenant scope is explicit: only an admission of the caller's college counts.
 */
final class AdmissionConversionGuard
{
    public const CANCELLED_MESSAGE = 'A cancelled admission cannot be converted to a student.';

    /**
     * Refuse the conversion when the application's admission in this college is
     * cancelled. Passes silently when there is no admission, or it is active.
     *
     * @param  string  $errorKey  The validation key the caller's form reports on
     *                             (`application_id` for the application route,
     *                             `admission` for the admission route).
     *
     * @throws ValidationException
     */
    public function assertLinkedAdmissionNotCancelled(int $applicationId, int $collegeId, string $errorKey): void
    {
        $cancelled = Admission::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('application_id', $applicationId)
            ->where('status', 'cancelled')
            ->lockForUpdate()
            ->first(['id']);

        if ($cancelled !== null) {
            throw ValidationException::withMessages([
                $errorKey => self::CANCELLED_MESSAGE,
            ]);
        }
    }
}
