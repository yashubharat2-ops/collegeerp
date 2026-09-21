<?php

namespace Tests\Feature\Marksheets;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Results\ResultTestHelpers;
use Tests\TestCase;

/**
 * Marksheets — printable published examination results (Examinations Phase 4A).
 *
 * Marksheets are derived, read-only documents rendered from published
 * ExamResult / ExamResultItem data. This suite covers the listing, the
 * printable marksheet, the published-only rule, tenant isolation, RBAC,
 * deterministic pagination, navigation gating and permission seeding.
 *
 * Fixtures reuse the Phase 3 helpers and drive the real calculation and
 * publishing endpoints, so every marksheet is generated from genuinely
 * calculated + published result data — never from hand-written rows.
 *
 * NOTE on negative assertions: the filter dropdowns legitimately contain
 * examination / program / section names, so whole-page `assertDontSee` calls
 * would be unreliable. Absence is therefore asserted against the result
 * table body (see `resultRows()`), never against the full page.
 */
class MarksheetsTest extends TestCase
{
    use ResultTestHelpers;

    private const VIEW = ['marksheets.view'];

    /**
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
     * The result table body of the marksheet listing: the only region
     * negative assertions may target, since the filter dropdowns above it
     * legitimately repeat examination / program / section names.
     */
    private function resultRows(string $html): string
    {
        $start = strpos($html, '<tbody>');
        $end = $start === false ? false : strpos($html, '</tbody>', $start);

        $this->assertNotFalse($start, 'The marksheets list must render a result table body.');
        $this->assertNotFalse($end, 'The marksheets list must render a result table body.');

        return substr($html, $start, $end - $start);
    }

    // ------------------------------------------------------------------- index

    public function test_authorized_user_can_view_marksheet_listing(): void
    {
        $f = $this->publishedFixture('MSA');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $response = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index'))
            ->assertOk();

        $rows = $this->resultRows($response->getContent());

        $this->assertStringContainsString($f['enrollment']->enrollment_number, $rows);
        $this->assertStringContainsString($f['student']->fullName(), $rows);
        $this->assertStringContainsString($f['ctx']['exam']->name, $rows);
        $this->assertStringContainsString('/marksheets/'.$f['result']->id, $rows);
    }

    public function test_super_admin_can_view_the_marksheet_listing(): void
    {
        $f = $this->publishedFixture('MSB');

        $response = $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('marksheets.index'))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );
    }

    public function test_marksheet_listing_requires_the_view_permission(): void
    {
        $f = $this->publishedFixture('MSC');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('marksheets.index'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('marksheets.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------- detail

    public function test_authorized_user_can_view_a_published_marksheet(): void
    {
        $f = $this->publishedFixture('MSD');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('marksheets.show', $f['result']))
            ->assertOk()
            // College header
            ->assertSee($f['college']->name)
            // Student / examination information
            ->assertSee($f['student']->fullName())
            ->assertSee($f['student']->student_number)
            ->assertSee($f['enrollment']->enrollment_number)
            ->assertSee($f['ctx']['prog']->name)
            ->assertSee($f['ctx']['sec']->name)
            ->assertSee($f['ctx']['exam']->name)
            ->assertSee($f['ctx']['year']->name)
            ->assertSee($f['ctx']['term']->name)
            // Subject rows
            ->assertSee($f['ctx']['subjects'][0]->name)
            ->assertSee($f['ctx']['subjects'][1]->name)
            // Stored totals
            ->assertSee('200.00')
            ->assertSee('150.00')
            ->assertSee('75.000%')
            ->assertSee('Pass')
            // Print action
            ->assertSee('Print Marksheet');
    }

    public function test_super_admin_can_view_a_published_marksheet(): void
    {
        $f = $this->publishedFixture('MSE');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('marksheets.show', $f['result']))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    public function test_marksheet_detail_requires_the_view_permission(): void
    {
        $f = $this->publishedFixture('MSF');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('marksheets.show', $f['result']))
            ->assertForbidden();
    }

    // ------------------------------------------------- published-only enforcement

    public function test_unpublished_result_cannot_be_viewed_as_a_marksheet(): void
    {
        $f = $this->calculatedFixture('MSG');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        // Direct access to the unpublished result is forbidden …
        $this->asCollege($f['college'], $user)
            ->get(route('marksheets.show', $f['result']))
            ->assertForbidden();

        // … and it is absent from the listing, even when the unpublished
        // filter is forced through the query string.
        foreach ([[], ['publication_status' => 'unpublished']] as $query) {
            $response = $this->asCollege($f['college'], $user)
                ->get(route('marksheets.index', $query))
                ->assertOk();

            $this->assertStringNotContainsString(
                $f['enrollment']->enrollment_number,
                $this->resultRows($response->getContent())
            );
        }
    }

    // ---------------------------------------------------------- tenant isolation

    public function test_marksheets_of_another_college_are_never_listed(): void
    {
        $f = $this->publishedFixture('MSH');
        $other = $this->makeCollege('MSI');

        $response = $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('marksheets.index'))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );
    }

    public function test_a_marksheet_from_another_college_cannot_be_opened(): void
    {
        $f = $this->publishedFixture('MSJ');
        $other = $this->makeCollege('MSK');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('marksheets.show', $f['result']))
            ->assertNotFound();
    }

    public function test_direct_url_tampering_cannot_cross_tenant_boundaries(): void
    {
        $collegeA = $this->makeCollege('MSL');
        $collegeB = $this->makeCollege('MSM');

        $ctx = $this->makeExamContextWithSubjects($collegeA, 'MSL', 1);
        [$student, $enrollment] = $this->makeEnrolledStudent($collegeA, $ctx, 'MSL');
        $scale = $this->makeGradeScale($collegeA);
        $this->recordMark($ctx['schedules'][0], $enrollment, 90.0);

        $calculator = $this->makeUserWithPermissions($collegeA, [
            'results.view', 'result_calculation.view', 'result_calculation.calculate',
        ]);
        $this->asCollege($collegeA, $calculator)->post(route('result-calculation.calculate'), [
            'examination_id' => $ctx['exam']->id,
            'grade_scale_id' => $scale->id,
        ])->assertRedirect();

        $resultId = $this->withTenant($collegeA, fn () => $this->resultFor($ctx['exam'], $enrollment)->id);

        $publisher = $this->makeUserWithPermissions($collegeA, [
            'results.view', 'result_publishing.view', 'result_publishing.publish',
        ]);
        $this->asCollege($collegeA, $publisher)
            ->post(route('result-publishing.publish', $resultId))
            ->assertRedirect();

        // A College B user manually crafting the College A result URL …
        $intruder = $this->makeUserWithPermissions($collegeB, self::VIEW);

        $this->asCollege($collegeB, $intruder)->get('/marksheets/'.$resultId)->assertNotFound();
        $this->asCollege($collegeB, $intruder)->get('/marksheets/'.($resultId + 100000))->assertNotFound();
        $this->asCollege($collegeB, $intruder)->get('/marksheets/not-an-id')->assertNotFound();

        // … while the owning college opens the very same URL untouched.
        $owner = $this->makeUserWithPermissions($collegeA, self::VIEW);
        $this->asCollege($collegeA, $owner)
            ->get('/marksheets/'.$resultId)
            ->assertOk()
            ->assertSee($student->fullName());
    }

    // ------------------------------------------------------------- soft deletes

    public function test_soft_deleted_results_disappear_from_the_list_and_detail(): void
    {
        $f = $this->publishedFixture('MSN');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);

        $this->withTenant($f['college'], fn () => $f['result']->delete());

        $response = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index'))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );

        $this->asCollege($f['college'], $user)
            ->get(route('marksheets.show', $f['result']))
            ->assertNotFound();
    }

    // -------------------------------------------- stored-data fidelity

    public function test_subject_rows_are_rendered_from_the_existing_result_items(): void
    {
        $f = $this->publishedFixture('MSO');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $stored = $this->withTenant($f['college'], function () use ($f) {
            return $f['result']->fresh()->items->map(fn ($item) => [
                'subject' => $item->examSchedule->subject->name,
                'max' => (string) $item->max_marks,
                'obtained' => (string) $item->obtained_marks,
                'grade' => $item->grade,
                'status' => $item->status,
            ])->all();
        });

        $this->assertCount(2, $stored);

        $response = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.show', $f['result']))
            ->assertOk();

        foreach ($stored as $item) {
            $response
                ->assertSee($item['subject'])
                ->assertSee($item['max'])
                ->assertSee($item['obtained'])
                ->assertSee($item['grade'])
                ->assertSee(ucfirst($item['status']));
        }
    }

    public function test_marks_and_grades_match_the_stored_result_data(): void
    {
        $f = $this->publishedFixture('MSP', [92.5, 61.0]);
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $stored = $this->withTenant($f['college'], function () use ($f) {
            $result = $f['result']->fresh();

            return [
                'total_max' => (string) $result->total_max_marks,
                'total_obtained' => (string) $result->total_obtained_marks,
                'percentage' => (string) $result->percentage,
                'grade' => $result->overall_grade,
                'status' => $result->result_status,
                'published_on' => $result->published_at->format('M d, Y'),
            ];
        });

        $this->asCollege($f['college'], $user)
            ->get(route('marksheets.show', $f['result']))
            ->assertOk()
            ->assertSee($stored['total_max'])
            ->assertSee($stored['total_obtained'])
            ->assertSee($stored['percentage'].'%')
            ->assertSee($stored['grade'])
            ->assertSee(ucfirst($stored['status']))
            ->assertSee($stored['published_on']);
    }

    public function test_reading_marksheets_never_writes_marks_or_results(): void
    {
        $f = $this->publishedFixture('MSQ');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $resultsBefore = DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all();
        $itemsBefore = DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all();

        $this->asCollege($f['college'], $user)->get(route('marksheets.index'))->assertOk();
        $this->asCollege($f['college'], $user)->get(route('marksheets.show', $f['result']))->assertOk();

        $this->assertSame($resultsBefore, DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($itemsBefore, DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all());
    }

    // ------------------------------------------------------------------ filters

    public function test_marksheets_can_be_filtered_by_examination(): void
    {
        $f = $this->publishedFixture('MSR');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $otherExam = $this->makeExamContextWithSubjects($f['college'], 'MSR2', 1)['exam'];

        $excluded = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index', ['examination_id' => $otherExam->id]))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($excluded->getContent())
        );

        $included = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index', ['examination_id' => $f['ctx']['exam']->id]))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($included->getContent())
        );
    }

    public function test_marksheets_can_be_filtered_by_result_status(): void
    {
        $passing = $this->publishedFixture('MSS');
        $user = $this->makeUserWithPermissions($passing['college'], self::VIEW);

        $this->assertSame('pass', $passing['result']->result_status);

        $excluded = $this->asCollege($passing['college'], $user)
            ->get(route('marksheets.index', ['result_status' => 'fail']))
            ->assertOk();

        $this->assertStringNotContainsString(
            $passing['enrollment']->enrollment_number,
            $this->resultRows($excluded->getContent())
        );

        $included = $this->asCollege($passing['college'], $user)
            ->get(route('marksheets.index', ['result_status' => 'pass']))
            ->assertOk();

        $this->assertStringContainsString(
            $passing['enrollment']->enrollment_number,
            $this->resultRows($included->getContent())
        );
    }

    public function test_marksheets_can_be_searched_by_student_and_enrollment_number(): void
    {
        $f = $this->publishedFixture('MST');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $found = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index', ['search' => $f['enrollment']->enrollment_number]))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($found->getContent())
        );

        $missing = $this->asCollege($f['college'], $user)
            ->get(route('marksheets.index', ['search' => 'no-such-student-xyz']))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($missing->getContent())
        );
    }

    // --------------------------------------------------------------- pagination

    public function test_marksheet_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('MSU');
        $ctx = $this->makeExamContextWithSubjects($college, 'MSU', 2);
        $scale = $this->makeGradeScale($college);

        for ($i = 1; $i <= 20; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'MSU'.$i);
            $this->recordMark($ctx['schedules'][0], $enrollment, 60.0);
            $this->recordMark($ctx['schedules'][1], $enrollment, 60.0);
        }

        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $publisher = $this->makeUserWithPermissions($college, ['results.view', 'result_publishing.view', 'result_publishing.publish']);
        $this->asCollege($college, $publisher)
            ->post(route('result-publishing.examination', $ctx['exam']))
            ->assertRedirect();

        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $this->assertSame(20, DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->whereNotNull('published_at')->count());

        $pageOne = $this->asCollege($college, $user)->get(route('marksheets.index'))->assertOk();
        $pageTwo = $this->asCollege($college, $user)->get(route('marksheets.index', ['page' => 2]))->assertOk();

        // 20 published results over a 15-per-page list: 15 rows on page one,
        // 5 on page two.
        $this->assertSame(15, substr_count($pageOne->getContent(), '/marksheets/'));
        $this->assertSame(5, substr_count($pageTwo->getContent(), '/marksheets/'));

        // Ordering is stable: newest first, so the two pages are disjoint and
        // together cover every result exactly once.
        $newestFirst = DB::table('exam_results')
            ->where('examination_id', $ctx['exam']->id)
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        foreach (array_slice($newestFirst, 0, 15) as $id) {
            $this->assertStringContainsString('/marksheets/'.$id, $pageOne->getContent());
            $this->assertStringNotContainsString('/marksheets/'.$id, $pageTwo->getContent());
        }

        foreach (array_slice($newestFirst, 15, 5) as $id) {
            $this->assertStringContainsString('/marksheets/'.$id, $pageTwo->getContent());
        }
    }

    // --------------------------------------------------------------- navigation

    public function test_navigation_shows_marksheets_with_the_view_permission(): void
    {
        $college = $this->makeCollege('MSV');
        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Examinations</div>'));

        $start = strpos($html, '>Examinations</div>') + strlen('>Examinations</div>');
        $end = strpos($html, 'uppercase tracking-widest', $start);
        $group = $end === false ? substr($html, $start) : substr($html, $start, $end - $start);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'));
        $this->assertStringContainsString(route('marksheets.index'), $group);
        $this->assertStringContainsString('Marksheets', $group);
    }

    public function test_navigation_hides_marksheets_without_the_view_permission(): void
    {
        $college = $this->makeCollege('MSW');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('marksheets.index'), false)
            ->assertDontSee('Marksheets');
    }

    // ------------------------------------------------------------------- seeder

    public function test_marksheets_permission_seeding_is_idempotent(): void
    {
        $this->assertSame(1, Permission::query()->where('slug', 'marksheets.view')->count());

        $this->seed();
        $this->seed();

        $this->assertSame(1, Permission::query()->where('slug', 'marksheets.view')->count());

        $permission = Permission::query()->where('slug', 'marksheets.view')->firstOrFail();
        $this->assertSame('marksheets', $permission->module);
        $this->assertSame('view', $permission->action);
        $this->assertTrue($permission->is_active);
    }
}
