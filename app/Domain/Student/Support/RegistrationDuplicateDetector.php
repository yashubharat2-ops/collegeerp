<?php

namespace App\Domain\Student\Support;

use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Duplicate-student detection for the registration wizard.
 *
 * Two clearly separated layers, because a name is not an identity:
 *
 *  1. BLOCKING (verified identifiers) — handled by
 *     {@see RegistrationIdentityGuard} and enforced on save, submit and
 *     approval. A phone, Aadhaar or APAAR/ABC ID already held by a live student
 *     of this college is a hard duplicate.
 *
 *  2. POSSIBLE (names + a corroborating detail) — this class. A matching name
 *     ALONE never blocks anything, and neither does a name + father's name:
 *     common names are common. A match is only reported when the name matches
 *     AND at least one further detail agrees (date of birth, or the father's /
 *     mother's name). The result is a warning that travels with the submission
 *     into the approval request, so a human decides — existing students are
 *     never rejected automatically.
 *
 * Everything is college-scoped (explicitly, without the global scope, so the
 * filter is identical for every caller) and soft-deleted rows are ignored.
 */
final class RegistrationDuplicateDetector
{
    /** How many possible duplicates to report (a review hint, not a report). */
    private const LIMIT = 5;

    /**
     * Students that look like the same person as this registration payload.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $ignoreStudentId  the student being resubmitted, if any
     * @return Collection<int, array{student_id: int, student_number: ?string, name: string, matched_on: array<int, string>}>
     */
    public static function possibleDuplicates(array $data, int $collegeId, ?int $ignoreStudentId = null): Collection
    {
        $first = self::normalise((string) ($data['first_name'] ?? ''));
        $last = self::normalise((string) ($data['last_name'] ?? ''));

        // Without a first name there is nothing to compare that would not match
        // half the college.
        if ($first === '') {
            return collect();
        }

        $father = self::normalise((string) ($data['father_name'] ?? ''));
        $mother = self::normalise((string) ($data['mother_name'] ?? ''));
        $dob = self::date((string) ($data['date_of_birth'] ?? ''));

        // Nothing corroborates the name, so no warning is raised: a shared or
        // very common name alone must never be treated as a duplicate.
        if ($father === '' && $mother === '' && $dob === null) {
            return collect();
        }

        $query = Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(first_name) = ?', [$first]);

        if ($last !== '') {
            $query->whereRaw('LOWER(COALESCE(last_name, ?)) = ?', ['', $last]);
        }

        if ($ignoreStudentId !== null) {
            $query->whereKeyNot($ignoreStudentId);
        }

        return $query
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'father_name', 'mother_name', 'registration_status'])
            ->map(function (Student $student) use ($father, $mother, $dob): ?array {
                $matched = [];

                if ($father !== '' && self::normalise((string) $student->father_name) === $father) {
                    $matched[] = "father's name";
                }

                if ($mother !== '' && self::normalise((string) $student->mother_name) === $mother) {
                    $matched[] = "mother's name";
                }

                if ($dob !== null && $student->date_of_birth !== null && $student->date_of_birth->format('Y-m-d') === $dob) {
                    $matched[] = 'date of birth';
                }

                if ($matched === []) {
                    return null;
                }

                return [
                    'student_id' => (int) $student->id,
                    'student_number' => $student->student_number,
                    'name' => trim(implode(' ', array_filter([
                        $student->first_name, $student->middle_name, $student->last_name,
                    ]))),
                    'matched_on' => $matched,
                ];
            })
            ->filter()
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * The warning rows stored on a draft / approval payload.
     *
     * @param  Collection<int, array<string, mixed>>  $duplicates
     * @return array<int, array<string, mixed>>
     */
    public static function toWarnings(Collection $duplicates): array
    {
        return $duplicates->map(fn (array $row) => [
            'student_id' => $row['student_id'],
            'student_number' => $row['student_number'],
            'name' => $row['name'],
            'matched_on' => $row['matched_on'],
            'message' => sprintf(
                'A student with the same name and %s already exists%s. Check before approving.',
                implode(' and ', $row['matched_on']),
                $row['student_number'] ? ' ('.$row['student_number'].')' : '',
            ),
        ])->all();
    }

    /**
     * Case- and whitespace-insensitive name for comparison.
     *
     * No fuzzy/partial matching is applied: a near-miss is not a duplicate, and
     * guessing would flag legitimate students with similar names.
     */
    private static function normalise(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($value);
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
