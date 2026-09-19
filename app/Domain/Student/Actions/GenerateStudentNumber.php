<?php

namespace App\Domain\Student\Actions;

use App\Models\College;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique student number per college, safe under concurrent requests.
 *
 * Format: STU-{YEAR}-{SEQ} e.g. STU-2026-0001
 *
 * YEAR is taken from the enrollment academic-year code when known, otherwise the
 * current calendar year. SEQ is a zero-padded 4-digit sequence, sequential per
 * college within that year context. The college row is locked for update to
 * serialize number generation per college — the same approach used by the
 * admission/applicant number generators — so two concurrent conversions cannot
 * mint the same number.
 *
 * The number is always server-generated and never trusted from the client.
 * The prefix/SHAPE is not an institution rule: it is parameterisable so future
 * formats (different prefixes, `college-code`/`initials`, custom separators)
 * can be adopted without restructuring the table or the model.
 */
class GenerateStudentNumber
{
    public function execute(int $collegeId, ?string $academicYearCode = null): string
    {
        return DB::transaction(function () use ($collegeId, $academicYearCode): string {
            // Lock the college row to serialize number generation per college.
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $academicYearCode ?? date('Y');

            // SEQ is computed only from numbers of this college in this year
            // context, so numbering restarts per academic year per college.
            $query = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId);

            $existingNumbers = $query->pluck('student_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $number) {
                // Number self-identification: only numbers carrying the current
                // year segment contribute to the sequence for that year.
                if (! str_starts_with($number, 'STU-'.$yearPart.'-')) {
                    continue;
                }

                if (preg_match('/(\d{4,})$/', $number, $matches)) {
                    $maxSeq = max($maxSeq, (int) $matches[1]);
                }
            }

            $nextSeq = $maxSeq + 1;

            $attempts = 0;
            $candidate = '';
            do {
                $candidate = sprintf('STU-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = Student::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('student_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            // Extremely unlikely fallback under pathological collisions.
            if ($exists) {
                $candidate = sprintf('STU-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
