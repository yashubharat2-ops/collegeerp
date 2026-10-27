<?php

namespace App\Domain\Student\Actions;

use App\Models\College;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Allocates a ROLL NUMBER inside one class/section scope, safe under concurrent
 * requests.
 *
 * Scope — the real academic model, not the whole college: a roll number has to
 * be unique for the students of the SAME academic year + programme + section
 * (that is the list it is printed on). The same number is therefore allowed in a
 * different section, programme or year, which is exactly how colleges number
 * students. The database carries the matching unique index
 * (`student_enrollments_scope_roll_number_unique`).
 *
 * When allocation happens — never earlier: a roll number only exists once the
 * academic year, programme and section of an enrollment are known. The wizard
 * shows `Pending` until then (see StudentIdentifierService); this action runs
 * inside StudentService::createEnrollment(), the one path that creates an
 * enrollment.
 *
 * Format: the plain sequence number ("1", "2", … "25"), zero-padded to two
 * digits so a class list sorts correctly. There is no institutional format to
 * preserve here — no roll number existed in the schema before this change — so
 * it is deliberately the simplest thing that can be printed on a register.
 *
 * Concurrency: the college row is locked for update, exactly like
 * GenerateStudentNumber / GenerateEnrollmentNumber / GenerateAdmissionNumber, so
 * two parallel enrollments cannot mint the same number. `MAX(...) + 1` is never
 * used on its own: the lock serializes the read-then-write, and the unique index
 * is the final backstop.
 */
class GenerateRollNumber
{
    /** Two-digit minimum so "1" sorts before "10" in a printed list. */
    private const PAD = 2;

    public function execute(
        int $collegeId,
        int $academicYearId,
        ?int $programId,
        ?int $sectionId,
        bool $collegeLockHeld = false,
    ): string {
        return DB::transaction(function () use ($collegeId, $academicYearId, $programId, $sectionId, $collegeLockHeld): string {
            // A roll number only means something inside a section: without one
            // there is no list to number, so nothing is allocated.
            if ($sectionId === null) {
                throw new \InvalidArgumentException('A roll number requires a section.');
            }

            if (! $collegeLockHeld) {
                College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
            }

            $existing = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('academic_year_id', $academicYearId)
                ->where('program_id', $programId)
                ->where('section_id', $sectionId)
                ->whereNull('deleted_at')
                ->pluck('roll_number');

            $max = 0;

            foreach ($existing as $number) {
                if ($number !== null && preg_match('/(\d+)$/', (string) $number, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            }

            $attempts = 0;
            $candidate = '';

            do {
                $candidate = str_pad((string) ($max + 1 + $attempts), self::PAD, '0', STR_PAD_LEFT);

                $taken = StudentEnrollment::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('program_id', $programId)
                    ->where('section_id', $sectionId)
                    ->whereNull('deleted_at')
                    ->where('roll_number', $candidate)
                    ->exists();

                $attempts++;
            } while ($taken && $attempts < 500);

            return $candidate;
        });
    }

    /**
     * Whether a roll number is already used inside this scope.
     *
     * Soft-deleted enrollments do not hold the number (they are history), which
     * mirrors the partial-uniqueness the registration workflows rely on.
     */
    public function isTaken(
        int $collegeId,
        int $academicYearId,
        ?int $programId,
        ?int $sectionId,
        string $rollNumber,
        ?int $exceptEnrollmentId = null,
    ): bool {
        if ($sectionId === null) {
            return false;
        }

        $query = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->where('program_id', $programId)
            ->where('section_id', $sectionId)
            ->whereNull('deleted_at')
            ->where('roll_number', $rollNumber);

        if ($exceptEnrollmentId !== null) {
            $query->whereKeyNot($exceptEnrollmentId);
        }

        return $query->exists();
    }
}
