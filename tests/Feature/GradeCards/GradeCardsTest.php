<?php

namespace Tests\Feature\GradeCards;

use App\Models\Permission;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase4\Phase4TestHelpers;
use Tests\TestCase;

/**
 * Grade Cards — printable published examination results with grades, grade
 * points and credits (Examinations Phase 4).
 *
 * Grade cards are derived, read-only documents rendered from published
 * ExamResult / ExamResultItem data (credits from the Subject master, grade
 * points from the result's own stored GradeScale). This suite covers the
 * listing, the printable grade card, the published-only rule, tenant
 * isolation, RBAC, deterministic pagination, navigation gating and permission
 * seeding — plus a scope guard that no SGPA/CGPA is invented anywhere.
 *
 * NOTE on negative assertions: the filter dropdowns legitimately contain
 * examination / program / section names, so absence is asserted against the
 * result table body (see `resultRows()`), never against the full page.
 */
class GradeCardsTest extends TestCase
{
    use Phase4TestHelpers;

    private const VIEW = ['grade_cards.view'];

    // ------------------------------------------------------------------- index

    public function test_authorized_user_can_view_grade_card_listing(): void
    {
        $f = $this->publishedFixture('GCA');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $response = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index'))
            ->assertOk();

        $rows = $this->resultRows($response->getContent());

        $this->assertStringContainsString($f['enrollment']->enrollment_number, $rows);
        $this->assertStringContainsString($f['student']->fullName(), $rows);
        $this->assertStringContainsString($f['ctx']['exam']->name, $rows);
        $this->assertStringContainsString('/grade-cards/'.$f['result']->id, $rows);
    }

    public function test_super_admin_can_view_the_grade_card_listing(): void
    {
        $f = $this->publishedFixture('GCB');

        $response = $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('grade-cards.index'))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );
    }

    public function test_grade_card_listing_requires_the_view_permission(): void
    {
        $f = $this->publishedFixture('GCC');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('grade-cards.index'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('grade-cards.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------- detail

    public function test_authorized_user_can_view_a_published_grade_card(): void
    {
        $f = $this->publishedFixture('GCD');

        $this->withTenant($f['college'], function () use ($f): void {
            Subject::query()->whereKey($f['ctx']['subjects'][0]->id)->update(['credits' => 4.00]);
            Subject::query()->whereKey($f['ctx']['subjects'][1]->id)->update(['credits' => 3.00]);
        });

        $points = $this->withTenant($f['college'], fn () => $f['scale']->items->mapWithKeys(
            fn ($band) => [$band->grade => (string) $band->grade_point]
        )->all());

        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.show', $f['result']))
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
            ->assertSee($f['scale']->name)
            // Subject rows with stored credits
            ->assertSee($f['ctx']['subjects'][0]->name)
            ->assertSee($f['ctx']['subjects'][1]->name)
            ->assertSee('4.00')
            ->assertSee('3.00')
            // Stored grades and stored grade-point lookups (A → 10, B → 7).
            ->assertSee($points['A'])
            ->assertSee($points['B'])
            ->assertSee('Pass')
            // Print action
            ->assertSee('Print Grade Card');
    }

    public function test_super_admin_can_view_a_published_grade_card(): void
    {
        $f = $this->publishedFixture('GCE');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('grade-cards.show', $f['result']))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    public function test_grade_card_detail_requires_the_view_permission(): void
    {
        $f = $this->publishedFixture('GCF');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('grade-cards.show', $f['result']))
            ->assertForbidden();
    }

    // ------------------------------------------------- published-only enforcement

    public function test_unpublished_result_cannot_be_viewed_as_a_grade_card(): void
    {
        $f = $this->calculatedFixture('GCG');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.show', $f['result']))
            ->assertForbidden();

        foreach ([[], ['publication_status' => 'unpublished']] as $query) {
            $response = $this->asCollege($f['college'], $user)
                ->get(route('grade-cards.index', $query))
                ->assertOk();

            $this->assertStringNotContainsString(
                $f['enrollment']->enrollment_number,
                $this->resultRows($response->getContent())
            );
        }
    }

    // ---------------------------------------------------------- tenant isolation

    public function test_grade_cards_of_another_college_are_never_listed(): void
    {
        $f = $this->publishedFixture('GCH');
        $other = $this->makeCollege('GCI');

        $response = $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('grade-cards.index'))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );
    }

    public function test_a_grade_card_from_another_college_cannot_be_opened(): void
    {
        $f = $this->publishedFixture('GCJ');
        $other = $this->makeCollege('GCK');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('grade-cards.show', $f['result']))
            ->assertNotFound();
    }

    public function test_direct_url_tampering_cannot_cross_tenant_boundaries(): void
    {
        $f = $this->publishedFixture('GCL');
        $other = $this->makeCollege('GCM');
        $intruder = $this->makeUserWithPermissions($other, self::VIEW);

        $this->asCollege($other, $intruder)->get('/grade-cards/'.$f['result']->id)->assertNotFound();
        $this->asCollege($other, $intruder)->get('/grade-cards/'.($f['result']->id + 100000))->assertNotFound();
        $this->asCollege($other, $intruder)->get('/grade-cards/not-an-id')->assertNotFound();

        $owner = $this->makeUserWithPermissions($f['college'], self::VIEW);
        $this->asCollege($f['college'], $owner)
            ->get('/grade-cards/'.$f['result']->id)
            ->assertOk()
            ->assertSee($f['student']->fullName());
    }

    // ------------------------------------------------------------- soft deletes

    public function test_soft_deleted_results_disappear_from_the_list_and_detail(): void
    {
        $f = $this->publishedFixture('GCN');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);

        $this->withTenant($f['college'], fn () => $f['result']->delete());

        $response = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index'))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($response->getContent())
        );

        $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.show', $f['result']))
            ->assertNotFound();
    }

    // -------------------------------------------- stored-data fidelity

    public function test_subject_rows_are_rendered_from_the_existing_result_items(): void
    {
        $f = $this->publishedFixture('GCO');

        $this->withTenant($f['college'], function () use ($f): void {
            Subject::query()->whereKey($f['ctx']['subjects'][0]->id)->update(['credits' => 4.00]);
            Subject::query()->whereKey($f['ctx']['subjects'][1]->id)->update(['credits' => 2.50]);
        });

        $stored = $this->withTenant($f['college'], function () use ($f) {
            $points = $f['scale']->items->mapWithKeys(
                fn ($band) => [$band->grade => (string) $band->grade_point]
            )->all();

            return $f['result']->fresh()->items->map(fn ($item) => [
                'subject' => $item->examSchedule->subject->name,
                'credits' => (string) $item->examSchedule->subject->credits,
                'marks' => (string) $item->obtained_marks.' / '.(string) $item->max_marks,
                'grade' => $item->grade,
                'point' => $points[$item->grade],
                'status' => $item->status,
            ])->all();
        });

        $this->assertCount(2, $stored);

        $response = $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('grade-cards.show', $f['result']))
            ->assertOk();

        foreach ($stored as $item) {
            $response
                ->assertSee($item['subject'])
                ->assertSee($item['credits'])
                ->assertSee($item['marks'])
                ->assertSee($item['grade'])
                ->assertSee($item['point'])
                ->assertSee(ucfirst($item['status']));
        }
    }

    public function test_overall_grade_and_point_match_the_stored_result_data(): void
    {
        $f = $this->publishedFixture('GCP', [92.5, 61.0]);

        $stored = $this->withTenant($f['college'], function () use ($f) {
            $result = $f['result']->fresh();
            $points = $f['scale']->items->mapWithKeys(
                fn ($band) => [$band->grade => (string) $band->grade_point]
            )->all();

            return [
                'grade' => $result->overall_grade,
                'point' => $points[$result->overall_grade],
                'status' => $result->result_status,
            ];
        });

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('grade-cards.show', $f['result']))
            ->assertOk()
            ->assertSee($stored['grade'])
            ->assertSee($stored['point'])
            ->assertSee(ucfirst($stored['status']));
    }

    public function test_no_sgpa_or_cgpa_is_invented_on_the_grade_card(): void
    {
        $f = $this->publishedFixture('GCQ');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('grade-cards.show', $f['result']))
            ->assertOk()
            ->assertDontSee('SGPA')
            ->assertDontSee('CGPA')
            ->assertDontSee('sgpa')
            ->assertDontSee('cgpa');
    }

    public function test_reading_grade_cards_never_writes_marks_or_results(): void
    {
        $f = $this->publishedFixture('GCR');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $resultsBefore = DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all();
        $itemsBefore = DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all();

        $this->asCollege($f['college'], $user)->get(route('grade-cards.index'))->assertOk();
        $this->asCollege($f['college'], $user)->get(route('grade-cards.show', $f['result']))->assertOk();

        $this->assertSame($resultsBefore, DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($itemsBefore, DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all());
    }

    // ------------------------------------------------------------------ filters

    public function test_grade_cards_can_be_filtered_by_examination(): void
    {
        $f = $this->publishedFixture('GCS');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $otherExam = $this->makeExamContextWithSubjects($f['college'], 'GCS2', 1)['exam'];

        $excluded = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index', ['examination_id' => $otherExam->id]))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($excluded->getContent())
        );

        $included = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index', ['examination_id' => $f['ctx']['exam']->id]))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($included->getContent())
        );
    }

    public function test_grade_cards_can_be_searched_by_student_and_enrollment_number(): void
    {
        $f = $this->publishedFixture('GCT');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $found = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index', ['search' => $f['enrollment']->enrollment_number]))
            ->assertOk();

        $this->assertStringContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($found->getContent())
        );

        $missing = $this->asCollege($f['college'], $user)
            ->get(route('grade-cards.index', ['search' => 'no-such-student-xyz']))
            ->assertOk();

        $this->assertStringNotContainsString(
            $f['enrollment']->enrollment_number,
            $this->resultRows($missing->getContent())
        );
    }

    // --------------------------------------------------------------- pagination

    public function test_grade_card_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('GCU');
        $ctx = $this->makeExamContextWithSubjects($college, 'GCU', 2);
        $scale = $this->makeGradeScale($college);

        for ($i = 1; $i <= 16; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'GCU'.$i);
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

        $this->assertSame(16, DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->whereNotNull('published_at')->count());

        $pageOne = $this->asCollege($college, $user)->get(route('grade-cards.index'))->assertOk();
        $pageTwo = $this->asCollege($college, $user)->get(route('grade-cards.index', ['page' => 2]))->assertOk();

        $this->assertSame(15, substr_count($pageOne->getContent(), '/grade-cards/'));
        $this->assertSame(1, substr_count($pageTwo->getContent(), '/grade-cards/'));

        $newestFirst = DB::table('exam_results')
            ->where('examination_id', $ctx['exam']->id)
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        foreach (array_slice($newestFirst, 0, 15) as $id) {
            $this->assertStringContainsString('/grade-cards/'.$id, $pageOne->getContent());
            $this->assertStringNotContainsString('/grade-cards/'.$id, $pageTwo->getContent());
        }

        $this->assertStringContainsString('/grade-cards/'.end($newestFirst), $pageTwo->getContent());
    }

    // --------------------------------------------------------------- navigation

    public function test_navigation_shows_grade_cards_with_the_view_permission(): void
    {
        $college = $this->makeCollege('GCV');
        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('grade-cards.index'), $html);
        $this->assertStringContainsString('Grade Cards', $html);
    }

    public function test_navigation_hides_grade_cards_without_the_view_permission(): void
    {
        $college = $this->makeCollege('GCW');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('grade-cards.index'), false)
            ->assertDontSee('Grade Cards');
    }

    // ------------------------------------------------------------------- seeder

    public function test_grade_cards_permission_seeding_is_idempotent(): void
    {
        $this->assertSame(1, Permission::query()->where('slug', 'grade_cards.view')->count());

        $this->seed();
        $this->seed();

        $this->assertSame(1, Permission::query()->where('slug', 'grade_cards.view')->count());

        $permission = Permission::query()->where('slug', 'grade_cards.view')->firstOrFail();
        $this->assertSame('grade_cards', $permission->module);
        $this->assertSame('view', $permission->action);
        $this->assertTrue($permission->is_active);
    }
}
