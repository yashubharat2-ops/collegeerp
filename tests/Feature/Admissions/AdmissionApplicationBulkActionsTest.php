<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admission\Services\AdmissionApplicationStatusService;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D: bulk review and CSV export for admission applications. Review uses
 * the same workflow map and submitted_at stamp as the single-record edit (via
 * AdmissionApplicationStatusService). Admission is never done in bulk.
 */
class AdmissionApplicationBulkActionsTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private const ENDPOINT = 'bulk-actions.execute';

    private function review(College $college, User $user, array $ids, string $status): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), [
            'module' => 'admission_applications',
            'action' => 'review',
            'ids' => $ids,
            'parameters' => ['status' => $status],
        ]);
    }

    private function reviewer(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.update']);
    }

    public function test_under_review_applications_can_be_approved_in_bulk_and_are_stamped_and_audited(): void
    {
        $college = $this->makeCollege('APBULK');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $one = $this->phaseDApplication($college, $applicant, 'under_review');
        $two = $this->phaseDApplication($college, $applicant, 'under_review');

        $this->review($college, $reviewer, [$one->id, $two->id], 'approved')
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ([$one, $two] as $application) {
            $fresh = AdmissionApplication::withoutGlobalScopes()->find($application->id);
            $this->assertSame('approved', $fresh->status);
            $this->assertNotNull($fresh->submitted_at);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'admission_application.updated', 'subject_id' => $one->id]);
    }

    public function test_draft_applications_skip_the_disallowed_jump_to_approved(): void
    {
        $college = $this->makeCollege('APDRAFT');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $draft = $this->phaseDApplication($college, $applicant, 'draft');

        $this->review($college, $reviewer, [$draft->id], 'approved')
            ->assertSessionHasErrors('bulk');

        $fresh = AdmissionApplication::withoutGlobalScopes()->find($draft->id);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->submitted_at);
    }

    public function test_mixed_selection_changes_only_workflow_approved_rows(): void
    {
        $college = $this->makeCollege('APMIX');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $draft = $this->phaseDApplication($college, $applicant, 'draft');
        $review = $this->phaseDApplication($college, $applicant, 'under_review');

        $this->review($college, $reviewer, [$draft->id, $review->id], 'rejected')
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '1 application updated')
                && str_contains($m, '1 was skipped'));

        $this->assertSame('draft', AdmissionApplication::withoutGlobalScopes()->find($draft->id)->status);
        $this->assertSame('rejected', AdmissionApplication::withoutGlobalScopes()->find($review->id)->status);
    }

    public function test_admitted_applications_cannot_be_moved_back_in_bulk(): void
    {
        $college = $this->makeCollege('APADM');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $admitted = $this->phaseDApplication($college, $applicant, 'admitted');

        $this->review($college, $reviewer, [$admitted->id], 'under_review')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('admitted', AdmissionApplication::withoutGlobalScopes()->find($admitted->id)->status);
    }

    public function test_bulk_admit_is_not_offered_and_is_rejected(): void
    {
        $college = $this->makeCollege('APNOADM');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $approved = $this->phaseDApplication($college, $applicant, 'approved');

        $this->review($college, $reviewer, [$approved->id], 'admitted')
            ->assertSessionHasErrors('bulk');
        $this->review($college, $reviewer, [$approved->id], 'cancelled')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('approved', AdmissionApplication::withoutGlobalScopes()->find($approved->id)->status);
    }

    public function test_an_existing_submission_stamp_is_preserved(): void
    {
        $college = $this->makeCollege('APSTAMP');
        $reviewer = $this->reviewer($college);
        $applicant = $this->phaseDApplicant($college);
        $submittedAt = now()->subDays(3)->startOfSecond();
        $application = $this->phaseDApplication($college, $applicant, 'under_review', ['submitted_at' => $submittedAt]);

        $this->review($college, $reviewer, [$application->id], 'approved')->assertSessionHas('success');

        $fresh = AdmissionApplication::withoutGlobalScopes()->find($application->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertEquals($submittedAt->timestamp, $fresh->submitted_at->timestamp);
    }

    public function test_the_shared_status_service_applies_the_same_rules_as_the_single_edit(): void
    {
        $service = app(AdmissionApplicationStatusService::class);
        $college = $this->makeCollege('APSVC');
        $draft = $this->phaseDApplication($college, $this->phaseDApplicant($college), 'draft');

        $this->assertFalse($service->canTransition('draft', 'approved'));
        $this->assertTrue($service->canTransition('draft', 'submitted'));

        // Leaving draft stamps submitted_at server-side; staying in draft does not.
        $this->assertArrayHasKey('submitted_at', $service->attributesForStatus($draft, 'submitted'));
        $this->assertArrayNotHasKey('submitted_at', $service->attributesForStatus($draft, 'draft'));
    }

    public function test_viewer_cannot_review_and_foreign_applications_are_untouched(): void
    {
        $college = $this->makeCollege('APVIEW');
        $other = $this->makeCollege('APVIEWB');
        $viewer = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = $this->phaseDApplicant($college);
        $own = $this->phaseDApplication($college, $applicant, 'under_review');
        $foreign = $this->phaseDApplication($other, $this->phaseDApplicant($other), 'under_review');

        $this->review($college, $viewer, [$own->id], 'approved')->assertSessionHasErrors('bulk');
        $this->review($college, $this->reviewer($college), [$foreign->id], 'approved')->assertSessionHasErrors('bulk');

        $this->assertSame('under_review', AdmissionApplication::withoutGlobalScopes()->find($own->id)->status);
        $this->assertSame('under_review', AdmissionApplication::withoutGlobalScopes()->find($foreign->id)->status);
    }

    public function test_export_streams_selected_applications_and_audits(): void
    {
        $college = $this->makeCollege('APEXP');
        $viewer = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = $this->phaseDApplicant($college, ['email' => '+cmd@example.test']);
        $selected = $this->phaseDApplication($college, $applicant, 'under_review', ['application_number' => 'APP-SELECTED1']);
        $this->phaseDApplication($college, $this->phaseDApplicant($college), 'draft', ['application_number' => 'APP-NOTSELECTED']);

        $response = $this->asCollege($college, $viewer)
            ->get(route('admission-applications.export', ['ids' => [$selected->id]]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('APP-SELECTED1', $csv);
        $this->assertStringNotContainsString('APP-NOTSELECTED', $csv);
        $this->assertStringContainsString("'+cmd@example.test", $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admission_applications.exported']);
    }

    public function test_applications_export_requires_view_permission(): void
    {
        $college = $this->makeCollege('APNOEXP');
        $nobody = $this->makeUserWithPermissions($college, []);
        $application = $this->phaseDApplication($college, $this->phaseDApplicant($college));

        $this->asCollege($college, $nobody)
            ->get(route('admission-applications.export', ['ids' => [$application->id]]))
            ->assertForbidden();
    }
}
