<?php

namespace Tests\Feature\Phase4;

use Tests\Feature\Results\ResultTestHelpers;

/**
 * Shared fixtures for the Examinations Phase 4 tests (Grade Cards, Exam
 * Reports, Student Result History).
 *
 * Reuses the Phase 3 helpers (college, users, enrollments, exam contexts,
 * grade scales, marks) and adds only what Phase 4 needs: genuinely
 * calculated + published result fixtures built through the real calculation
 * and publishing endpoints, plus table-body extraction for reliable negative
 * assertions.
 */
trait Phase4TestHelpers
{
    use ResultTestHelpers;

    /**
     * A calculated AND published two-subject result, built through the real
     * Phase 3 endpoints — never from hand-written rows.
     *
     * @param  array<int, float>  $marks
     * @return array<string, mixed>
     */
    private function publishedFixture(string $prefix, array $marks = [80.0, 70.0]): array
    {
        $f = $this->calculatedFixture($prefix, $marks);

        $publisher = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_publishing.view', 'result_publishing.publish',
        ]);

        $this->asCollege($f['college'], $publisher)
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $f['result'] = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment'])->fresh());

        return $f;
    }

    /**
     * @param  array<int, float>  $marks
     * @return array<string, mixed>
     */
    private function calculatedFixture(string $prefix, array $marks = [80.0, 70.0]): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);
        $scale = $this->makeGradeScale($college);

        $this->recordMark($ctx['schedules'][0], $enrollment, $marks[0]);
        $this->recordMark($ctx['schedules'][1], $enrollment, $marks[1]);

        $calculator = $this->makeUserWithPermissions($college, [
            'results.view', 'result_calculation.view', 'result_calculation.calculate',
        ]);

        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        return compact('college', 'ctx', 'student', 'enrollment', 'scale', 'result');
    }

    /**
     * The first result table body of a listing page: the only region negative
     * assertions may target, since filter dropdowns legitimately repeat
     * examination / program / section names.
     */
    private function resultRows(string $html): string
    {
        $bodies = $this->tableBodies($html);

        $this->assertNotEmpty($bodies, 'The page must render a result table body.');

        return $bodies[0];
    }

    /**
     * @return array<int, string>
     */
    private function tableBodies(string $html): array
    {
        preg_match_all('/<tbody>(.*?)<\/tbody>/s', $html, $matches);

        return $matches[1] ?? [];
    }

    /**
     * The table row containing $needle, for precise per-row cell assertions.
     */
    private function rowFor(string $tbody, string $needle): string
    {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $tbody, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail("No table row contains '{$needle}'.");

        return '';
    }
}
