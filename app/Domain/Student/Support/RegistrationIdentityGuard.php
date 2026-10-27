<?php

namespace App\Domain\Student\Support;

use App\Models\Student;
use Illuminate\Validation\ValidationException;

/**
 * "One live student per phone / Aadhaar / APAAR per college", applied at the
 * wizard boundary, at submit and again at approval.
 *
 * The rules mirror StoreStudentRegistrationRequest exactly: a PENDING or
 * REJECTED registration may be resubmitted without creating a second person
 * record (StudentRegistrationService then reuses that row), while an approved
 * or legacy student blocks reuse of the same phone or Aadhaar.
 *
 * The Aadhaar is compared through its keyed digest, so no plain number is
 * queried, logged or returned.
 */
final class RegistrationIdentityGuard
{
    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $ignoreStudentId  the student being resubmitted/approved,
     *                                     which must not block itself
     *
     * @throws ValidationException
     */
    public static function assertAvailable(array $data, int $collegeId, ?int $ignoreStudentId = null): void
    {
        $errors = [];

        $phone = isset($data['phone']) && is_string($data['phone']) ? trim($data['phone']) : '';

        if ($phone !== '' && self::scoped($collegeId, $ignoreStudentId)->where('phone', $phone)->exists()) {
            $errors['phone'] = ['The phone has already been taken.'];
        }

        // APAAR / ABC ID is an issued, verified national identifier: like the
        // Aadhaar it must identify exactly one student in the college.
        $apaar = isset($data['apaar_id']) && is_string($data['apaar_id']) ? trim($data['apaar_id']) : '';

        if ($apaar !== '' && self::scoped($collegeId, $ignoreStudentId)->where('apaar_id', $apaar)->exists()) {
            $errors['apaar_id'] = ['This APAAR / ABC ID is already recorded for another student in this college.'];
        }

        $digits = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        if ($digits !== null) {
            if (! Aadhaar::isValid($digits)) {
                $errors['aadhaar_number'] = ['Enter a valid 12-digit Aadhaar number.'];
            } elseif (self::scoped($collegeId, $ignoreStudentId)->where('aadhaar_hash', Aadhaar::hash($digits))->exists()) {
                $errors['aadhaar_number'] = ['This Aadhaar number is already recorded for another student in this college.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Students that block reuse: approved registrations and legacy records.
     *
     * Built without the global scope on purpose — the college filter is applied
     * explicitly so the query is identical for every caller, and a
     * soft-deleted row is excluded.
     */
    private static function scoped(int $collegeId, ?int $ignoreStudentId = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = self::operationalStudentQuery($collegeId);

        if ($ignoreStudentId !== null) {
            $query->whereKeyNot($ignoreStudentId);
        }

        return $query;
    }

    private static function operationalStudentQuery(int $collegeId): \Illuminate\Database\Eloquent\Builder
    {
        return Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('registration_status')
                    ->orWhereNotIn('registration_status', [
                        Student::REGISTRATION_PENDING,
                        Student::REGISTRATION_REJECTED,
                    ]);
            });
    }
}
