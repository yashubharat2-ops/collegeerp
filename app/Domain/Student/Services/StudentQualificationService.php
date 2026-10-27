<?php

namespace App\Domain\Student\Services;

use App\Models\Student;
use App\Models\StudentQualification;
use Illuminate\Validation\ValidationException;

/**
 * Previous qualifications of a student.
 *
 * Kept strictly apart from two neighbours that already exist:
 *   - `StudentAcademicRecord` is this college's progression ledger (which
 *     semester the student is in, promoted/retained), with no marks at all;
 *   - `Exams` / `ExamResult` are the institution's OWN examinations.
 * A board/university result earned elsewhere therefore belongs here and
 * nowhere else, and saving a qualification can never look like an enrollment.
 *
 * Marks arithmetic:
 *   - percentage is derived ONLY from a valid marks pair (obtained / max, both
 *     numeric, max > 0, obtained <= max). A grade-based result keeps its
 *     percentage empty — a derived number must never replace the grade the
 *     board actually issued;
 *   - a percentage supplied alongside a grade-based result is discarded for the
 *     same reason.
 */
class StudentQualificationService
{
    /** Fields a caller may set; anything else is ignored. */
    public const FILLABLE = [
        'level', 'qualification', 'examination_year', 'board_university', 'institution',
        'roll_number', 'registration_number', 'subjects',
        'marks_obtained', 'max_marks', 'percentage', 'grade', 'grade_letter',
        'result_status', 'remarks',
    ];

    /**
     * Replace the student's qualification list with the submitted rows.
     *
     * Rows carrying an `id` that belongs to this student are updated, rows
     * without an id are created, and rows that are no longer present are
     * soft-deleted. The entire sync happens in the caller's transaction, so a
     * failure leaves the previous list intact.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, StudentQualification>
     */
    public function syncForStudent(Student $student, array $rows, int $collegeId, ?int $actorId = null): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && $this->hasContent($row)));

        $existing = StudentQualification::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('id');

        $kept = [];
        $saved = [];

        foreach ($rows as $row) {
            $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
            $attributes = $this->payload($row);

            // The level is the one column the schema requires: a row that names
            // no level cannot be placed in a student's history.
            if (blank($attributes['level'] ?? null)) {
                throw ValidationException::withMessages([
                    'qualifications' => 'Choose the qualification level for every previous qualification.',
                ]);
            }

            if ($id !== null) {
                $model = $existing->get($id);

                if ($model === null) {
                    // A foreign or stale id must never be adopted silently.
                    throw ValidationException::withMessages([
                        'qualifications' => 'One of the qualification rows no longer exists for this student. Reload the page and try again.',
                    ]);
                }

                $kept[] = $id;
                $model->update($attributes + ['updated_by' => $actorId]);
                $saved[] = $model;

                continue;
            }

            $saved[] = StudentQualification::create($attributes + [
                'college_id' => $collegeId,
                'student_id' => $student->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }

        $toDelete = $existing->keys()->diff($kept);

        if ($toDelete->isNotEmpty()) {
            // Soft delete keeps the previous statement on record.
            StudentQualification::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->whereIn('id', $toDelete->all())
                ->delete();
        }

        return $saved;
    }

    /**
     * Persist a single qualification row (used when a qualification is added
     * outside the wizard, e.g. a correction after approval).
     *
     * @param  array<string, mixed>  $row
     */
    public function create(Student $student, array $row, int $collegeId, ?int $actorId = null): StudentQualification
    {
        return StudentQualification::create($this->payload($row) + [
            'college_id' => $collegeId,
            'student_id' => $student->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * Normalise one submitted row into column values.
     *
     * The percentage rule lives here alone, so the wizard, an import and any
     * future caller cannot disagree about it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        $attributes = [];

        foreach (self::FILLABLE as $key) {
            if (array_key_exists($key, $row)) {
                $attributes[$key] = is_string($row[$key]) ? trim($row[$key]) : $row[$key];
            }
        }

        foreach (['qualification', 'board_university', 'institution', 'roll_number', 'registration_number', 'grade', 'grade_letter', 'remarks'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] === '') {
                $attributes[$key] = null;
            }
        }

        $attributes['marks_obtained'] = $this->number($row['marks_obtained'] ?? null);
        $attributes['max_marks'] = $this->number($row['max_marks'] ?? null);

        $grade = $attributes['grade'] ?? null;
        $gradeLetter = $attributes['grade_letter'] ?? null;
        $gradeBased = filled($grade) || filled($gradeLetter);

        $derived = $this->percentage($attributes['marks_obtained'], $attributes['max_marks']);

        // A grade-based result keeps what the board issued: no derived
        // percentage is written, and a manually supplied one is dropped.
        $attributes['percentage'] = $gradeBased ? null : $derived;

        if (array_key_exists('subjects', $row)) {
            // The wizard sends subject LINES ("Mathematics | 100 | 88"); an API
            // caller may send the structured array directly. Both end up as the
            // same normalised JSON.
            $attributes['subjects'] = is_string($row['subjects'])
                ? $this->subjectsFromText($row['subjects'])
                : $this->subjects($row['subjects']);
        }

        return $attributes;
    }

    /**
     * Structured subjects, when the caller supplies them.
     *
     * The subject list is stored as JSON on the qualification row — the schema
     * already had no subject table for external results, and inventing one
     * would duplicate the college's own subject master for records that come
     * from other boards. Each subject is reduced to name + max/obtained marks.
     *
     * @return array<int, array{name: string, max_marks: float|null, marks_obtained: float|null}>
     */
    public function subjects(mixed $subjects): array
    {
        if (is_string($subjects)) {
            $decoded = json_decode($subjects, true);
            $subjects = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($subjects)) {
            return [];
        }

        $clean = [];

        foreach ($subjects as $subject) {
            if (is_string($subject)) {
                $name = trim($subject);
                if ($name !== '') {
                    $clean[] = ['name' => $name, 'max_marks' => null, 'marks_obtained' => null];
                }
                continue;
            }

            if (! is_array($subject)) {
                continue;
            }

            $name = trim((string) ($subject['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $clean[] = [
                'name' => mb_substr($name, 0, 150),
                'max_marks' => $this->number($subject['max_marks'] ?? null),
                'marks_obtained' => $this->number($subject['marks_obtained'] ?? null),
            ];
        }

        return array_slice($clean, 0, 30);
    }

    /**
     * Parse the wizard's subject lines into the structured `subjects` JSON.
     *
     * Accepted per line: `Mathematics | 100 | 88` (name, maximum, obtained) or
     * just `Mathematics`. Nothing is inferred: a line without marks keeps them
     * empty instead of assuming full marks.
     *
     * @return array<int, array{name: string, max_marks: float|null, marks_obtained: float|null}>
     */
    public function subjectsFromText(string $text): array
    {
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $this->subjects($decoded);
        }

        $subjects = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            $subjects[] = [
                'name' => $parts[0],
                'max_marks' => $parts[1] ?? null,
                'marks_obtained' => $parts[2] ?? null,
            ];
        }

        return $this->subjects($subjects);
    }

    /** A non-negative decimal, or null when the input is not a usable number. */
    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number < 0 ? null : round($number, 2);
    }

    /**
     * Only a meaningful marks pair produces a percentage.
     *
     * Returns null for "not applicable" instead of 0 so a missing result is
     * never displayed as a fail.
     */
    private function percentage(?float $obtained, ?float $max): ?float
    {
        if ($obtained === null || $max === null || $max <= 0 || $obtained > $max) {
            return null;
        }

        return round($obtained / $max * 100, 2);
    }

    /** A row that is entirely empty is a blank table line, not a record. */
    private function hasContent(array $row): bool
    {
        foreach (['level', 'qualification', 'examination_year', 'board_university', 'institution', 'roll_number', 'grade'] as $key) {
            if (filled($row[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** The student's live qualifications, oldest exam first. */
    public function forStudent(Student $student): \Illuminate\Support\Collection
    {
        return $student->qualifications()->get();
    }
}
