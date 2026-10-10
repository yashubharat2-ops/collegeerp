<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionEnquiry;
use App\Models\College;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D: bulk status change and CSV export for admission enquiries.
 * `converted` is never a bulk target and is never changed by a bulk action.
 */
class AdmissionEnquiryBulkActionsTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private const ENDPOINT = 'bulk-actions.execute';

    private function changeStatus(College $college, User $user, array $ids, string $status): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), [
            'module' => 'admission_enquiries',
            'action' => 'change_status',
            'ids' => $ids,
            'parameters' => ['status' => $status],
        ]);
    }

    private function editor(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.update']);
    }

    public function test_bulk_status_change_applies_allowed_transitions_and_audits_each_row(): void
    {
        $college = $this->makeCollege('EQBULK');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $one = $this->phaseDEnquiry($college, $applicant, ['status' => 'new']);
        $two = $this->phaseDEnquiry($college, $applicant, ['status' => 'contacted']);

        $this->changeStatus($college, $editor, [$one->id, $two->id], 'followed_up')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('followed_up', AdmissionEnquiry::withoutGlobalScopes()->find($one->id)->status);
        $this->assertSame('followed_up', AdmissionEnquiry::withoutGlobalScopes()->find($two->id)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admission_enquiry.updated', 'subject_id' => $one->id]);
    }

    public function test_converted_enquiries_are_skipped_and_never_changed_in_bulk(): void
    {
        $college = $this->makeCollege('EQCONV');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $converted = $this->phaseDEnquiry($college, $applicant, ['status' => 'converted']);
        $fresh = $this->phaseDEnquiry($college, $applicant, ['status' => 'new']);

        $this->changeStatus($college, $editor, [$converted->id, $fresh->id], 'closed')
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '1 enquiry updated')
                && str_contains($m, '1 was skipped'));

        $this->assertSame('converted', AdmissionEnquiry::withoutGlobalScopes()->find($converted->id)->status);
        $this->assertSame('closed', AdmissionEnquiry::withoutGlobalScopes()->find($fresh->id)->status);
    }

    public function test_only_converted_selection_reports_failure_and_changes_nothing(): void
    {
        $college = $this->makeCollege('EQCONVONLY');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $converted = $this->phaseDEnquiry($college, $applicant, ['status' => 'converted']);

        $this->changeStatus($college, $editor, [$converted->id], 'dropped')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('converted', AdmissionEnquiry::withoutGlobalScopes()->find($converted->id)->status);
    }

    public function test_converted_is_rejected_as_a_bulk_target(): void
    {
        $college = $this->makeCollege('EQTGT');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $new = $this->phaseDEnquiry($college, $applicant, ['status' => 'new']);

        $this->changeStatus($college, $editor, [$new->id], 'converted')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('new', AdmissionEnquiry::withoutGlobalScopes()->find($new->id)->status);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $college = $this->makeCollege('EQBAD');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $new = $this->phaseDEnquiry($college, $applicant, ['status' => 'new']);

        $this->changeStatus($college, $editor, [$new->id], 'deleted')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('new', AdmissionEnquiry::withoutGlobalScopes()->find($new->id)->status);
    }

    public function test_viewer_without_update_permission_cannot_change_status(): void
    {
        $college = $this->makeCollege('EQVIEW');
        $viewer = $this->makeUserWithPermissions($college, ['admission_enquiries.view']);
        $applicant = $this->phaseDApplicant($college);
        $new = $this->phaseDEnquiry($college, $applicant, ['status' => 'new']);

        $this->changeStatus($college, $viewer, [$new->id], 'contacted')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('new', AdmissionEnquiry::withoutGlobalScopes()->find($new->id)->status);
    }

    public function test_foreign_college_enquiries_are_never_changed(): void
    {
        $college = $this->makeCollege('EQTENA');
        $other = $this->makeCollege('EQTENB');
        $editor = $this->editor($college);
        $foreignApplicant = $this->phaseDApplicant($other);
        $foreign = $this->phaseDEnquiry($other, $foreignApplicant, ['status' => 'new']);

        $this->changeStatus($college, $editor, [$foreign->id], 'contacted')
            ->assertSessionHasErrors('bulk');

        $this->assertSame('new', AdmissionEnquiry::withoutGlobalScopes()->find($foreign->id)->status);
    }

    public function test_export_streams_selected_rows_with_formula_cells_neutralised(): void
    {
        $college = $this->makeCollege('EQEXP');
        $viewer = $this->makeUserWithPermissions($college, ['admission_enquiries.view']);
        $applicant = $this->phaseDApplicant($college, ['first_name' => '=HYPERLINK("http://x.test")', 'last_name' => 'Doe']);
        $selected = $this->phaseDEnquiry($college, $applicant, ['enquiry_number' => 'ENQ-SELECTED1']);
        $this->phaseDEnquiry($college, $this->phaseDApplicant($college, ['first_name' => 'Other']), ['enquiry_number' => 'ENQ-NOTSELECTED']);

        $response = $this->asCollege($college, $viewer)
            ->get(route('admission-enquiries.export', ['ids' => [$selected->id]]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('ENQ-SELECTED1', $csv);
        $this->assertStringNotContainsString('ENQ-NOTSELECTED', $csv);
        // A formula-looking name is written as text, not as a live formula.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admission_enquiries.exported']);
    }

    public function test_export_requires_view_permission(): void
    {
        $college = $this->makeCollege('EQNOEXP');
        $nobody = $this->makeUserWithPermissions($college, []);
        $applicant = $this->phaseDApplicant($college);
        $enquiry = $this->phaseDEnquiry($college, $applicant);

        $this->asCollege($college, $nobody)
            ->get(route('admission-enquiries.export', ['ids' => [$enquiry->id]]))
            ->assertForbidden();
    }

    public function test_listing_shows_the_bulk_bar_with_status_choices_but_no_converted(): void
    {
        $college = $this->makeCollege('EQLIST');
        $editor = $this->editor($college);
        $applicant = $this->phaseDApplicant($college);
        $this->phaseDEnquiry($college, $applicant);

        $this->asCollege($college, $editor)
            ->get(route('admission-enquiries.index'))
            ->assertOk()
            ->assertSee('data-module="admission_enquiries"', false)
            ->assertSee('data-bulk-action="change_status"', false)
            ->assertSee('data-bulk-param-status="contacted"', false)
            ->assertDontSee('data-bulk-param-status="converted"', false);
    }
}
