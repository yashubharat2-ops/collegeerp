<?php

namespace App\Domain\Student\Services;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ONE place that answers "what does the academic structure look like here?".
 *
 * The registration wizard, the New/Edit Enrollment form and the Student Profile
 * all need the same four levels in the same order:
 *
 *   Academic Year → Programme / Course → Semester / Year of study → Section / Batch
 *
 * and they must not each invent their own list. Before this service the wizard
 * rendered every section of the college and a free-text semester, the enrollment
 * form rendered every section, and the profile had nothing to resolve a label
 * from — three different answers to the same question.
 *
 * What the schema actually models (and therefore what this service can offer):
 *
 *   AcademicYear  — the session (2026-27). Owns nothing else directly.
 *   Program       — the course. Sections belong to a (year, program) pair.
 *   AcademicTerm  — the semester / term / trimester master. It is scoped to a
 *                   COLLEGE and an ACADEMIC YEAR (there is no per-programme term
 *                   configuration in this database), so the semester list is
 *                   derived from the selected year. This is a documented
 *                   limitation, not a shortcut: inventing a programme→term table
 *                   would be master data the office has never entered.
 *   Section       — the batch/cohort: exactly one (year, program) pair.
 *
 * Everything is tenant-scoped: the models carry CollegeScope, and every query is
 * additionally filtered by the explicit college id so a caller that has not set
 * the ambient tenant still cannot read another college's structure.
 */
class AcademicContextService
{
    /** Banner set on a term label when its type is not "semester". */
    private const TYPE_SUFFIX = [
        'semester' => '',
        'term' => ' (term)',
        'trimester' => ' (trimester)',
    ];

    /**
     * Active academic terms of one academic year, in study order.
     *
     * @return Collection<int, AcademicTerm>
     */
    public function terms(?int $academicYearId, int $collegeId): Collection
    {
        if ($academicYearId === null) {
            return collect();
        }

        return AcademicTerm::query()
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'active')
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'college_id', 'academic_year_id', 'name', 'code', 'type', 'sequence', 'status']);
    }

    /**
     * Sections of one academic year (and programme, when chosen), in name order.
     *
     * A programme-less lookup returns only sections that are not tied to a
     * programme, because a section always belongs to exactly one programme and
     * StudentService refuses a section that the enrollment's programme does not
     * own. Offering it here would be offering a combination the server rejects.
     *
     * @return Collection<int, Section>
     */
    public function sections(?int $academicYearId, ?int $programId, int $collegeId): Collection
    {
        if ($academicYearId === null) {
            return collect();
        }

        return Section::query()
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($programId === null, fn ($query) => $query->whereNull('program_id'))
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'college_id', 'academic_year_id', 'program_id', 'name', 'code', 'status']);
    }

    /**
     * The payload both the wizard's dependent selects and the enrollment form
     * consume. Ids + labels only: no student data, so a GET is safe.
     *
     * @return array{terms: array<int, array{id: int, label: string, code: string}>, sections: array<int, array{id: int, label: string, code: string, program_id: int|null}>}
     */
    public function options(?int $academicYearId, ?int $programId, int $collegeId): array
    {
        return [
            'terms' => $this->terms($academicYearId, $collegeId)
                ->map(fn (AcademicTerm $term) => [
                    'id' => (int) $term->id,
                    'label' => $this->termLabel($term),
                    'code' => (string) $term->code,
                ])->values()->all(),
            'sections' => $this->sections($academicYearId, $programId, $collegeId)
                ->map(fn (Section $section) => [
                    'id' => (int) $section->id,
                    'label' => $this->sectionLabel($section),
                    'code' => (string) $section->code,
                    'program_id' => $section->program_id === null ? null : (int) $section->program_id,
                ])->values()->all(),
        ];
    }

    /** "Semester 3 (SEM3)" — the label every screen shows for a configured level. */
    public function termLabel(?AcademicTerm $term): string
    {
        if ($term === null) {
            return '';
        }

        $suffix = self::TYPE_SUFFIX[(string) $term->type] ?? '';

        return trim($term->name.$suffix.($term->code ? ' ('.$term->code.')' : ''));
    }

    /** "Section A (A)" — the abbreviation is what class registers print. */
    public function sectionLabel(?Section $section): string
    {
        if ($section === null) {
            return '';
        }

        return trim($section->name.($section->code ? ' ('.$section->code.')' : ''));
    }

    /**
     * Resolve a submitted academic-term id inside the enrollment's own context.
     *
     * Returns null when nothing was chosen. A term that belongs to another
     * college, another academic year or is inactive is a field error, never a
     * silent null: storing the enrollment without the level the office picked
     * would lose the selection without telling anyone.
     */
    public function resolveTerm(?int $termId, ?int $academicYearId, int $collegeId, string $field = 'academic_term_id'): ?AcademicTerm
    {
        if ($termId === null) {
            return null;
        }

        $term = AcademicTerm::query()
            ->where('college_id', $collegeId)
            ->whereKey($termId)
            ->first();

        if ($term === null) {
            throw ValidationException::withMessages([
                $field => 'Choose a semester / year of study that belongs to this college.',
            ]);
        }

        if ($academicYearId === null || (int) $term->academic_year_id !== (int) $academicYearId) {
            throw ValidationException::withMessages([
                $field => 'The selected semester / year of study does not belong to the selected academic year.',
            ]);
        }

        return $term;
    }

    /**
     * The stored label for an enrollment: the configured term's name when one is
     * attached, otherwise the free-text value an older record carries.
     *
     * Kept as one method so a profile, an ID card and an export cannot disagree
     * about what "semester" means.
     */
    public function semesterLabel(?AcademicTerm $term, ?string $freeText): ?string
    {
        if ($term !== null) {
            return (string) $term->name;
        }

        $freeText = is_string($freeText) ? trim($freeText) : null;

        return $freeText === '' ? null : $freeText;
    }

    /**
     * The academic year that contains today, among the given ids.
     *
     * Used to decide which of a student's active enrollments is the CURRENT one
     * by the academic calendar rather than by whichever row was inserted last.
     */
    public function yearContainingToday(int $collegeId): ?AcademicYear
    {
        $today = now()->toDateString();

        return AcademicYear::query()
            ->where('college_id', $collegeId)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }
}
