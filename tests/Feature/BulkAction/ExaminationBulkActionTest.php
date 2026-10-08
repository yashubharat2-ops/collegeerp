<?php

namespace Tests\Feature\BulkAction;

use App\Models\ExamAttendance;
use App\Models\Examination;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\GradeScale;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Results\ResultTestHelpers;
use Tests\TestCase;

/**
 * Examination bulk actions — selection, export authorization, policy
 * enforcement and tenant isolation.
 *
 * The whole Examination family is deliberately EXPORT-ONLY, and these tests pin
 * it down: marks, exam attendance, results, grade scales, exam schedules,
 * marksheets and grade cards each register exactly one action (`export`).
 * Nothing bulk-mutates marks, attendance, results, grades or schedules, and
 * result publishing keeps its single, pre-existing path
 * (ResultPublishingService) instead of a second publishing system.
 *
 * What every export must guarantee, and what is asserted here:
 *  - ids from the browser are re-queried inside the active college;
 *  - each record passes the module's own permission AND, where the handler
 *    declares one, its policy ability;
 *  - the CSV endpoint re-resolves the ids, so a hand-edited URL can never widen
 *    a download (unpublished results and unpublished marksheets included);
 *  - no Aadhaar / government identity number and no document path is exported.
 */
class ExaminationBulkActionTest extends TestCase
{
    use ResultTestHelpers;

    private const CALCULATE_PERMISSIONS = [
        'results.view',
        'result_calculation.view',
        'result_calculation.calculate',
        'result_calculation.recalculate',
    ];

    private const PUBLISH_PERMISSIONS = [
        'results.view',
        'result_publishing.view',
        'result_publishing.publish',
        'result_publishing.unpublish',
    ];

    /**
     * Every examination module, with the model its export handler operates on.
     *
     * @return array<string, class-string>
     */
    private function examinationModules(): array
    {
        return [
            'examinations' => Examination::class,
            'exam_schedules' => ExamSchedule::class,
            'exam_attendance' => ExamAttendance::class,
            'exam_marks' => ExamMark::class,
            'results' => ExamResult::class,
            'result_calculation' => ExamResult::class,
            'grade_scales' => GradeScale::class,
            'result_publishing' => ExamResult::class,
            'marksheets' => ExamResult::class,
            'grade_cards' => ExamResult::class,
            'exam_reports' => Program::class,
            'exam_report_subjects' => Subject::class,
        ];
    }

    /**
     * A calculated (but not yet published) result with all its fixtures.
     *
     * @return array<string, mixed>
     */
    private function calculatedFixture(string $prefix): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);
        $scale = $this->makeGradeScale($college);

        $this->recordMark($ctx['schedules'][0], $enrollment, 80.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);

        $calculator = $this->makeUserWithPermissions($college, self::CALCULATE_PERMISSIONS);

        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        $this->assertInstanceOf(ExamResult::class, $result);
        $this->assertTrue($result->isUnpublished(), 'The fixture must start unpublished.');

        return compact('college', 'ctx', 'student', 'enrollment', 'scale', 'result');
    }

    /**
     * A user of that college holding the given permissions.
     */
    private function staff(array $fixture, array $slugs): User
    {
        return $this->makeUserWithPermissions($fixture['college'], $slugs);
    }

    private function publisher(array $fixture): User
    {
        return $this->staff($fixture, self::PUBLISH_PERMISSIONS);
    }

    private function markIds(array $fixture): array
    {
        return ExamMark::withoutGlobalScopes()
            ->where('college_id', $fixture['college']->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function firstMarkId(array $fixture): int
    {
        return ExamMark::withoutGlobalScopes()
            ->where('college_id', $fixture['college']->id)
            ->orderBy('id')
            ->value('id');
    }

    /**
     * The `ids` the given export URL carries, as integers.
     *
     * @return array<int, int>
     */
    private function idsInUrl(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map('intval', array_values((array) ($query['ids'] ?? [])));
    }

    // ------------------------------------------------------------- registration

    public function test_examination_modules_register_export_only_actions(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->examinationModules() as $module => $model) {
            $registered = $registry->getForModule($module);

            $this->assertSame(
                ['export'],
                array_keys($registered),
                "Module {$module} must expose the export action and nothing destructive.",
            );
            $this->assertSame($model, $registry->get($module, 'export')->modelClass());
        }
    }

    // ---------------------------------------------------------------- selection

    public function test_examination_listings_render_the_shared_selection_controls(): void
    {
        $fixture = $this->calculatedFixture('XSEL');
        $college = $fixture['college'];

        $user = $this->staff($fixture, array_merge(self::PUBLISH_PERMISSIONS, [
            'results.view_unpublished',
            'examinations.view',
            'exam_schedules.view',
            'exam_attendance.view',
            'exam_marks.view',
            'result_calculation.view',
            'grade_scales.view',
            'marksheets.view',
            'grade_cards.view',
            'exam_reports.view',
        ]));

        $attendance = ExamAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'exam_schedule_id' => $fixture['ctx']['schedules'][0]->id,
            'student_enrollment_id' => $fixture['enrollment']->id,
            'attendance_status' => ExamAttendance::STATUS_PRESENT,
            'marked_at' => now(),
        ]);

        // Every listing that owns an export action must carry the shared
        // selection bar, the header select-all and a checkbox per rendered row.
        $pages = [
            ['examinations.index', 'examinations', $fixture['ctx']['exam']->id],
            ['exam-schedules.index', 'exam_schedules', $fixture['ctx']['schedules'][0]->id],
            ['exam-attendance.index', 'exam_attendance', $attendance->id],
            ['exam-marks.index', 'exam_marks', $this->firstMarkId($fixture)],
            ['results.index', 'results', $fixture['result']->id],
            ['result-calculation.index', 'result_calculation', $fixture['result']->id],
            ['grade-scales.index', 'grade_scales', $fixture['scale']->id],
            ['result-publishing.index', 'result_publishing', $fixture['result']->id],
        ];

        foreach ($pages as [$route, $module, $rowId]) {
            $response = $this->asCollege($college, $user)->get(route($route, [
                'examination_id' => $fixture['ctx']['exam']->id,
            ]));

            $response->assertOk();
            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$module.'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee('value="'.$rowId.'"', false);
        }

        // The exam reports and the derived documents only have rows once the
        // result is published, so they are asserted in that state.
        $this->asCollege($college, $this->publisher($fixture))
            ->post(route('result-publishing.publish', $fixture['result']))
            ->assertRedirect();

        $publishedPages = [
            ['exam-reports.index', 'exam_reports', $fixture['ctx']['prog']->id],
            ['exam-reports.index', 'exam_report_subjects', $fixture['ctx']['sub']->id],
            ['marksheets.index', 'marksheets', $fixture['result']->id],
            ['grade-cards.index', 'grade_cards', $fixture['result']->id],
        ];

        foreach ($publishedPages as [$route, $module, $rowId]) {
            $response = $this->asCollege($college, $user)->get(route($route, [
                'examination_id' => $fixture['ctx']['exam']->id,
            ]));

            $response->assertOk();
            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$module.'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee('value="'.$rowId.'"', false);
        }
    }

    public function test_marksheet_listing_stays_empty_until_the_result_is_published(): void
    {
        $fixture = $this->calculatedFixture('XMS1');

        $user = $this->staff($fixture, ['marksheets.view']);

        $this->asCollege($fixture['college'], $user)->get(route('marksheets.index'))
            ->assertOk()
            ->assertDontSee('data-select-row', false);

        $this->asCollege($fixture['college'], $this->publisher($fixture))
            ->post(route('result-publishing.publish', $fixture['result']))
            ->assertRedirect();

        $this->asCollege($fixture['college'], $user)->get(route('marksheets.index'))
            ->assertOk()
            ->assertSee('data-select-row', false)
            ->assertSee('value="'.$fixture['result']->id.'"', false);
    }

    // ------------------------------------------------------------- export flow

    public function test_examination_bulk_export_streams_only_authorized_records(): void
    {
        $fixture = $this->calculatedFixture('XEXP');
        $college = $fixture['college'];

        $user = $this->staff($fixture, [
            'examinations.view',
            'exam_schedules.view',
            'exam_marks.view',
        ]);

        // The examination listing row → the examinations CSV.
        $response = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'examinations',
            'action' => 'export',
            'ids' => [$fixture['ctx']['exam']->id],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'affected' => 1, 'skipped_unauthorized' => 0]);

        $redirect = $response->json('data.redirect');
        $this->assertSame([$fixture['ctx']['exam']->id], $this->idsInUrl($redirect));

        $csv = $this->asCollege($college, $user)->get($redirect);
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('Exam XEXP', $body);
        // No sensitive identity data: the student master never enters these CSVs.
        $this->assertStringNotContainsString('aadhaar', strtolower($body));
        $this->assertStringNotContainsString('apaar', strtolower($body));

        // The schedule export travels through the same pipeline.
        $scheduleExport = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_schedules',
            'action' => 'export',
            'ids' => [$fixture['ctx']['schedules'][0]->id],
        ])->assertOk()->json('data.redirect');

        $this->assertSame([$fixture['ctx']['schedules'][0]->id], $this->idsInUrl($scheduleExport));
        $this->assertStringContainsString(
            'Sub XEXP',
            $this->asCollege($college, $user)->get($scheduleExport)->streamedContent(),
        );

        // ...and so does the marks export, one row per selected mark.
        $marksExport = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_marks',
            'action' => 'export',
            'ids' => $this->markIds($fixture),
        ])->assertOk()->assertJson(['affected' => 2])->json('data.redirect');

        $marksBody = $this->asCollege($college, $user)->get($marksExport)->streamedContent();
        $this->assertStringContainsString($fixture['enrollment']->enrollment_number, $marksBody);
        $this->assertStringContainsString('80', $marksBody);
    }

    public function test_examination_exports_require_their_listing_permission(): void
    {
        $fixture = $this->calculatedFixture('XPERM');
        $college = $fixture['college'];

        // Grade scales only — nothing else in the Examination family.
        $user = $this->staff($fixture, ['grade_scales.view']);

        $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_marks',
            'action' => 'export',
            'ids' => [1],
        ])->assertStatus(403);

        $this->asCollege($college, $user)
            ->get(route('exam-marks.export', ['ids' => [1]]))
            ->assertStatus(403);

        $this->asCollege($college, $user)
            ->get(route('marksheets.export', ['ids' => [1]]))
            ->assertStatus(403);
    }

    public function test_examination_exports_skip_another_colleges_ids(): void
    {
        $fixtureA = $this->calculatedFixture('XTNA');
        $fixtureB = $this->calculatedFixture('XTNB');

        $userA = $this->staff($fixtureA, ['exam_marks.view']);
        $markA = $this->firstMarkId($fixtureA);
        $markB = $this->firstMarkId($fixtureB);

        $response = $this->asCollege($fixtureA['college'], $userA)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_marks',
            'action' => 'export',
            'ids' => [$markA, $markB],
        ]);

        // The foreign id is silently dropped: 1 affected, 1 skipped.
        $response->assertOk();
        $response->assertJson(['success' => true, 'affected' => 1, 'skipped_unauthorized' => 1]);

        $redirect = $response->json('data.redirect');
        $this->assertSame([$markA], $this->idsInUrl($redirect));

        $body = $this->asCollege($fixtureA['college'], $userA)->get($redirect)->streamedContent();
        $this->assertStringContainsString($fixtureA['enrollment']->enrollment_number, $body);
        $this->assertStringNotContainsString($fixtureB['enrollment']->enrollment_number, $body);

        // A hand-crafted URL naming the foreign id exports nothing from it.
        $handMade = $this->asCollege($fixtureA['college'], $userA)
            ->get(route('exam-marks.export', ['ids' => [$markB]]));

        $handMade->assertOk();
        $this->assertStringNotContainsString($fixtureB['enrollment']->enrollment_number, $handMade->streamedContent());
    }

    // ------------------------------------------------------- policy enforcement

    public function test_unpublished_results_are_only_exported_with_the_unpublished_permission(): void
    {
        $fixture = $this->calculatedFixture('XUNP');
        $college = $fixture['college'];
        $resultId = $fixture['result']->id;

        $this->assertTrue($fixture['result']->isUnpublished());

        // `results.view` alone: the policy denies this unpublished record, so the
        // whole selection is refused.
        $viewer = $this->staff($fixture, ['results.view']);

        $denied = $this->asCollege($college, $viewer)->postJson(route('bulk-actions.execute'), [
            'module' => 'results',
            'action' => 'export',
            'ids' => [$resultId],
        ]);

        $denied->assertStatus(403);
        $this->assertStringContainsString('authorized', $denied->json('error'));

        // A hand-edited URL cannot get past it either: the endpoint pins the query
        // to published rows for a viewer without the extra permission.
        $handMade = $this->asCollege($college, $viewer)
            ->get(route('results.export', ['ids' => [$resultId]]));

        $handMade->assertOk();
        $this->assertStringNotContainsString($fixture['enrollment']->enrollment_number, $handMade->streamedContent());

        // `results.view_unpublished` is the rule that unlocks it.
        $unpublishedViewer = $this->staff($fixture, ['results.view', 'results.view_unpublished']);

        $allowed = $this->asCollege($college, $unpublishedViewer)->postJson(route('bulk-actions.execute'), [
            'module' => 'results',
            'action' => 'export',
            'ids' => [$resultId],
        ]);

        $allowed->assertOk();
        $allowed->assertJson(['success' => true, 'affected' => 1]);

        $body = $this->asCollege($college, $unpublishedViewer)
            ->get($allowed->json('data.redirect'))
            ->streamedContent();

        $this->assertStringContainsString($fixture['enrollment']->enrollment_number, $body);
        $this->assertStringContainsString('unpublished', strtolower($body));
    }

    public function test_published_results_are_exported_by_the_results_document_modules(): void
    {
        $fixture = $this->calculatedFixture('XPUB');
        $college = $fixture['college'];

        $this->asCollege($college, $this->publisher($fixture))
            ->post(route('result-publishing.publish', $fixture['result']))
            ->assertRedirect();

        $user = $this->staff($fixture, array_merge(self::PUBLISH_PERMISSIONS, [
            'marksheets.view',
            'grade_cards.view',
        ]));

        $exports = [
            'results' => 'results.export',
            'marksheets' => 'marksheets.export',
            'grade_cards' => 'grade-cards.export',
            'result_publishing' => 'result-publishing.export',
        ];

        foreach ($exports as $module => $route) {
            $redirect = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$fixture['result']->id],
            ])->assertOk()->assertJson(['affected' => 1])->json('data.redirect');

            $this->assertStringStartsWith(route($route), $redirect);

            $body = $this->asCollege($college, $user)->get($redirect)->streamedContent();

            $this->assertStringContainsString($fixture['enrollment']->enrollment_number, $body);
            $this->assertStringContainsString('published', strtolower($body));
        }
    }

    public function test_marksheets_never_export_an_unpublished_result_even_when_the_id_is_sent(): void
    {
        $fixture = $this->calculatedFixture('XMSU');
        $college = $fixture['college'];

        $user = $this->staff($fixture, ['marksheets.view']);

        // The handler's published-only rule rejects the whole selection.
        $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'marksheets',
            'action' => 'export',
            'ids' => [$fixture['result']->id],
        ])->assertStatus(403);

        $csv = $this->asCollege($college, $user)
            ->get(route('marksheets.export', ['ids' => [$fixture['result']->id]]));

        $csv->assertOk();
        $this->assertStringNotContainsString($fixture['enrollment']->enrollment_number, $csv->streamedContent());
    }

    // --------------------------------------------------------- safe publishing

    public function test_result_publishing_keeps_its_existing_service_path_and_exposes_the_shared_export(): void
    {
        $fixture = $this->calculatedFixture('XPUB2');
        $college = $fixture['college'];
        $publisher = $this->publisher($fixture);

        // The worklist exposes the shared selection controls (row ids = result ids)...
        $this->asCollege($college, $publisher)->get(route('result-publishing.index'))
            ->assertOk()
            ->assertSee('data-module="result_publishing"', false)
            ->assertSee('data-bulk-action="export"', false)
            ->assertSee('name="result_ids[]"', false)
            ->assertSee('data-select-row', false);

        // ...and publishing still goes through the existing form + service, whose
        // endpoint re-resolves the ids inside the active college.
        $this->asCollege($college, $publisher)
            ->post(route('result-publishing.bulk'), ['result_ids' => [$fixture['result']->id]])
            ->assertRedirect();

        $this->assertTrue(
            $this->withTenant($college, fn () => $fixture['result']->refresh()->isPublished())
        );

        // The shared bulk endpoint offers NO publishing action, and no other
        // mutation: it is not a second publishing system.
        foreach (['publish' => 422, 'unpublish' => 422, 'delete' => 422] as $action => $status) {
            $this->asCollege($college, $publisher)->postJson(route('bulk-actions.execute'), [
                'module' => 'result_publishing',
                'action' => $action,
                'ids' => [$fixture['result']->id],
            ])->assertStatus($status);
        }
    }

    // ------------------------------------------------------------ exam reports

    public function test_exam_report_bulk_export_re_aggregates_the_selected_lines(): void
    {
        $fixture = $this->calculatedFixture('XREP');
        $college = $fixture['college'];

        $this->asCollege($college, $this->publisher($fixture))
            ->post(route('result-publishing.publish', $fixture['result']))
            ->assertRedirect();

        $user = $this->staff($fixture, ['exam_reports.view']);

        // The program-wise line is keyed by the Program it summarises, and the
        // screen's examination filter travels as a bulk parameter.
        $programExport = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_reports',
            'action' => 'export',
            'ids' => [$fixture['ctx']['prog']->id],
            'parameters' => ['examination_id' => $fixture['ctx']['exam']->id],
        ])->assertOk()->json('data.redirect');

        $this->assertSame([$fixture['ctx']['prog']->id], $this->idsInUrl($programExport));

        $programBody = $this->asCollege($college, $user)->get($programExport)->streamedContent();
        $this->assertStringContainsString($fixture['ctx']['prog']->name, $programBody);

        // The subject-wise sibling is a separate module, so program ids and
        // subject ids can never share one selection.
        $subjectExport = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_report_subjects',
            'action' => 'export',
            'ids' => [$fixture['ctx']['sub']->id],
            'parameters' => ['examination_id' => $fixture['ctx']['exam']->id],
        ])->assertOk()->json('data.redirect');

        $subjectBody = $this->asCollege($college, $user)->get($subjectExport)->streamedContent();
        $this->assertStringContainsString($fixture['ctx']['sub']->name, $subjectBody);
    }

    public function test_exam_report_export_is_not_reachable_from_another_college(): void
    {
        $fixture = $this->calculatedFixture('XREPF');

        $this->asCollege($fixture['college'], $this->publisher($fixture))
            ->post(route('result-publishing.publish', $fixture['result']))
            ->assertRedirect();

        $otherCollege = $this->makeCollege('XREPO');
        $otherUser = $this->makeUserWithPermissions($otherCollege, ['exam_reports.view']);

        // The program belongs to the first college, so nothing is authorized.
        $this->asCollege($otherCollege, $otherUser)->postJson(route('bulk-actions.execute'), [
            'module' => 'exam_reports',
            'action' => 'export',
            'ids' => [$fixture['ctx']['prog']->id],
            'parameters' => ['examination_id' => $fixture['ctx']['exam']->id],
        ])->assertStatus(403);

        // A forged examination id does not reveal the other college's summary
        // either: the report is re-aggregated inside the active college and the
        // program id is not in it.
        $csv = $this->asCollege($otherCollege, $otherUser)
            ->get(route('exam-reports.export', [
                'ids' => [$fixture['ctx']['prog']->id],
                'examination_id' => $fixture['ctx']['exam']->id,
            ]));

        $csv->assertOk();
        $this->assertStringNotContainsString($fixture['ctx']['prog']->name, $csv->streamedContent());
    }

    public function test_grade_scale_export_lists_the_configured_bands(): void
    {
        $fixture = $this->calculatedFixture('XGS');

        $user = $this->staff($fixture, ['grade_scales.view']);

        $redirect = $this->asCollege($fixture['college'], $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'grade_scales',
            'action' => 'export',
            'ids' => [$fixture['scale']->id],
        ])->assertOk()->json('data.redirect');

        $body = $this->asCollege($fixture['college'], $user)->get($redirect)->streamedContent();

        $this->assertStringContainsString($fixture['scale']->name, $body);
        $this->assertStringContainsString('Grade', $body);
        // The band set is re-derived server-side: the 80% mark lands in band A
        // and the 70% mark in band B, so both configured grades are present.
        $this->assertStringContainsString('A', $body);
        $this->assertStringContainsString('B', $body);
    }

    public function test_result_calculation_export_requires_results_visibility(): void
    {
        $fixture = $this->calculatedFixture('XCAL');
        $college = $fixture['college'];

        // `result_calculation.view` alone grants the engine, never result data.
        $engineOnly = $this->staff($fixture, ['result_calculation.view']);

        $this->asCollege($college, $engineOnly)->postJson(route('bulk-actions.execute'), [
            'module' => 'result_calculation',
            'action' => 'export',
            'ids' => [$fixture['result']->id],
        ])->assertStatus(403);

        $this->asCollege($college, $engineOnly)
            ->get(route('result-calculation.export', ['ids' => [$fixture['result']->id]]))
            ->assertStatus(403);

        // The worklist itself is not rendered for that user either.
        $this->asCollege($college, $engineOnly)
            ->get(route('result-calculation.index', ['examination_id' => $fixture['ctx']['exam']->id]))
            ->assertOk()
            ->assertDontSee('data-select-row', false);

        // With `results.view` the export works and carries the scoped rows.
        $viewer = $this->staff($fixture, ['results.view', 'results.view_unpublished', 'result_calculation.view']);

        $redirect = $this->asCollege($college, $viewer)->postJson(route('bulk-actions.execute'), [
            'module' => 'result_calculation',
            'action' => 'export',
            'ids' => [$fixture['result']->id],
        ])->assertOk()->json('data.redirect');

        $this->assertStringContainsString(
            $fixture['enrollment']->enrollment_number,
            $this->asCollege($college, $viewer)->get($redirect)->streamedContent(),
        );
    }

    // ---------------------------------------------------------------- safety net

    public function test_bulk_endpoint_rejects_any_unregistered_examination_mutation(): void
    {
        $fixture = $this->calculatedFixture('XSAFE');
        $college = $fixture['college'];

        $user = $this->staff($fixture, array_merge(self::PUBLISH_PERMISSIONS, [
            'examinations.view',
            'exam_schedules.view',
            'exam_attendance.view',
            'exam_marks.view',
            'grade_scales.view',
        ]));

        $attempts = [
            ['exam_marks', 'delete'],
            ['exam_marks', 'update'],
            ['exam_attendance', 'delete'],
            ['results', 'unpublish'],
            ['results', 'delete'],
            ['exam_schedules', 'cancel'],
            ['examinations', 'publish'],
            ['grade_scales', 'delete'],
        ];

        foreach ($attempts as [$module, $action]) {
            // The central endpoint validates the action against the registry, so
            // anything that was never registered is refused with a 422.
            $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => $action,
                'ids' => [1],
            ])->assertStatus(422);
        }

        // Nothing was touched: the recorded marks are byte-identical.
        $marks = DB::table('exam_marks')->where('college_id', $college->id)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->assertCount(2, $marks);
        $this->assertSame([80.0, 70.0], array_map(fn (array $row) => (float) $row['obtained_marks'], $marks));

        $this->assertSame(1, DB::table('exam_results')->where('college_id', $college->id)->count());
        $this->assertNull($fixture['result']->published_at);
    }
}
