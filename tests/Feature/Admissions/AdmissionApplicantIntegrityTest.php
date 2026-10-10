<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\College;
use App\Models\User;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D: applicant bulk export (view-gated, tenant-scoped, audited, no sensitive
 * columns), the applicant soft-delete guard (F), and null-safe related pages.
 */
class AdmissionApplicantIntegrityTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private function viewer(College $college): User
    {
        return $this->makeUserWithPermissions($college, [
            'admission_applicants.view', 'admission_applicants.update', 'admission_applicants.delete',
            'admission_enquiries.view', 'admission_applications.view', 'admissions.view',
        ]);
    }

    public function test_applicant_export_requires_view_permission(): void
    {
        $college = $this->makeCollege('AIEXPNO');
        $nobody = $this->makeUserWithPermissions($college, []);
        $applicant = $this->phaseDApplicant($college);

        $this->asCollege($college, $nobody)
            ->get(route('admission-applicants.export', ['ids' => [$applicant->id]]))
            ->assertForbidden();
    }

    public function test_applicant_export_is_selection_scoped_audited_and_excludes_sensitive_columns(): void
    {
        $college = $this->makeCollege('AIEXP');
        $other = $this->makeCollege('AIEXPB');
        $viewer = $this->viewer($college);
        $selected = $this->phaseDApplicant($college, [
            'first_name' => 'Selected', 'address' => 'SECRET ADDRESS 99', 'email' => 'sel@example.test',
        ]);
        $this->phaseDApplicant($college, ['first_name' => 'NotSelected']);
        $foreign = $this->phaseDApplicant($other, ['first_name' => 'ForeignPerson']);

        $response = $this->asCollege($college, $viewer)
            ->get(route('admission-applicants.export', ['ids' => [$selected->id, $foreign->id]]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Selected', $csv);
        $this->assertStringNotContainsString('NotSelected', $csv);
        $this->assertStringNotContainsString('ForeignPerson', $csv);
        // Address is not part of the applicant export column set.
        $this->assertStringNotContainsString('SECRET ADDRESS 99', $csv);
        $this->assertStringNotContainsString('Address', $csv);
        $this->assertStringNotContainsStringIgnoringCase('aadhaar', $csv);
        $this->assertStringNotContainsStringIgnoringCase('password', $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'admission_applicants.exported']);
    }

    public function test_applicant_bulk_export_redirects_to_the_export_with_authorized_ids_only(): void
    {
        $college = $this->makeCollege('AIBULK');
        $other = $this->makeCollege('AIBULKB');
        $viewer = $this->viewer($college);
        $own = $this->phaseDApplicant($college);
        $foreign = $this->phaseDApplicant($other);

        $response = $this->asCollege($college, $viewer)->post(route('bulk-actions.execute'), [
            'module' => 'admission_applicants',
            'action' => 'export',
            'ids' => [$own->id, $foreign->id],
        ]);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('admission-applicants/export', $location);

        // Only the id this college may act on travels to the export endpoint.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame([$own->id], array_map('intval', $query['ids'] ?? []));
    }

    public function test_applicant_with_a_live_application_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('AIDELAPP');
        $viewer = $this->viewer($college);
        $applicant = $this->phaseDApplicant($college);
        $this->phaseDApplication($college, $applicant, 'draft');

        $this->asCollege($college, $viewer)
            ->delete(route('admission-applicants.destroy', $applicant))
            ->assertSessionHasErrors('applicant');

        $this->assertNotNull(AdmissionApplicant::withoutGlobalScopes()->find($applicant->id));
        $this->assertNull(AdmissionApplicant::withoutGlobalScopes()->find($applicant->id)->deleted_at);
    }

    public function test_applicant_with_a_live_enquiry_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('AIDELENQ');
        $viewer = $this->viewer($college);
        $applicant = $this->phaseDApplicant($college);
        $this->phaseDEnquiry($college, $applicant);

        $this->asCollege($college, $viewer)
            ->delete(route('admission-applicants.destroy', $applicant))
            ->assertSessionHasErrors('applicant');

        $this->assertNull(AdmissionApplicant::withoutGlobalScopes()->find($applicant->id)->deleted_at);
    }

    public function test_applicant_with_a_live_admission_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('AIDELADM');
        $viewer = $this->viewer($college);
        $admission = $this->phaseDAdmission($college);

        $this->asCollege($college, $viewer)
            ->delete(route('admission-applicants.destroy', $admission->applicant_id))
            ->assertSessionHasErrors('applicant');

        $this->assertNull(AdmissionApplicant::withoutGlobalScopes()->find($admission->applicant_id)->deleted_at);
    }

    public function test_applicant_without_dependents_can_still_be_deleted(): void
    {
        $college = $this->makeCollege('AIDELOK');
        $viewer = $this->viewer($college);
        $applicant = $this->phaseDApplicant($college);

        $this->asCollege($college, $viewer)
            ->delete(route('admission-applicants.destroy', $applicant))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(AdmissionApplicant::withoutGlobalScopes()->withTrashed()->find($applicant->id)->deleted_at);
    }

    public function test_list_pages_stay_up_and_show_a_marker_when_an_applicant_record_is_missing(): void
    {
        $college = $this->makeCollege('AINULL');
        $viewer = $this->viewer($college);
        $applicant = $this->phaseDApplicant($college, ['first_name' => 'Gone']);
        $enquiry = $this->phaseDEnquiry($college, $applicant);
        $application = $this->phaseDApplication($college, $applicant, 'under_review', ['enquiry_id' => $enquiry->id]);
        $admission = $this->phaseDAdmission($college, [
            'applicant_id' => $applicant->id,
            'application_id' => $application->id,
        ]);

        // Simulate a legacy/orphaned state: the applicant is soft-deleted while
        // dependents still reference it (the guard above prevents this path now).
        AdmissionApplicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['deleted_at' => now()]);

        $this->asCollege($college, $viewer)->get(route('admission-enquiries.index'))
            ->assertOk()->assertSee('Applicant record missing');
        $this->asCollege($college, $viewer)->get(route('admission-applications.index'))
            ->assertOk()->assertSee('Applicant record missing');
        $this->asCollege($college, $viewer)->get(route('admissions.index'))
            ->assertOk()->assertSee('Applicant record missing');

        // The dependents themselves are untouched; the integrity issue stays visible.
        $this->assertNotNull(AdmissionEnquiry::withoutGlobalScopes()->find($enquiry->id));
        $this->assertNotNull(AdmissionApplication::withoutGlobalScopes()->find($application->id));
        $this->assertNotNull(\App\Models\Admission::withoutGlobalScopes()->find($admission->id));
    }
}
