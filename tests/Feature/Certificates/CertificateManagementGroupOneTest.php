<?php

namespace Tests\Feature\Certificates;

use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\StudentTransfer;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class CertificateManagementGroupOneTest extends TestCase
{
    use StudentTestHelpers;

    private function certificatePermissions(): array
    {
        return [
            'students.view', 'student_history.view',
            'certificates_dashboard.view', 'certificates_templates.view', 'certificates_templates.manage',
            'certificates_generation.view', 'certificates_issuance.view', 'certificates_issuance.create',
            'certificates_verification.view', 'certificates_requests.view', 'certificates_requests.create',
            'certificates_requests.manage', 'certificates_requests.review', 'certificates_reports.view',
        ];
    }

    public function test_students_has_no_tc_menu_or_route_and_certificate_management_is_the_only_entry(): void
    {
        $college = $this->makeCollege('CERTNAV');
        $user = $this->makeUserWithPermissions($college, $this->certificatePermissions());
        $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()
            ->assertSee('Certificate Management (EC)')
            ->assertSee('Certificate Dashboard')->assertSee('Certificate Templates')
            ->assertSee('Certificate Generation')->assertSee('Certificate Issuance')
            ->assertSee('Certificate Verification')->assertSee('Certificate Requests')->assertSee('Certificate Reports')
            ->assertDontSee('Transfer / TC');

        $this->assertFalse(Route::has('student-transfers.index'));
        $this->assertFalse(Route::has('student-transfers.issue'));
        $this->assertFalse(Route::has('student-transfers.store'));
        $this->assertTrue(Route::has('certificates.transfer-requests.index'));
        $this->assertTrue(Route::has('certificates.tc.pdf'));
    }

    public function test_group_one_generates_bonafide_and_character_and_tc_reuses_transfer_history(): void
    {
        $college = $this->makeCollege('CERTFLOW');
        $admin = $this->makeUserWithPermissions($college, $this->certificatePermissions());
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['student_number' => 'CERT-001']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)->post(route('certificates.templates.store'), [
            'type' => 'bonafide', 'name' => 'Bonafide Standard',
            'body' => 'We certify {{student_name}} is registered as {{student_number}} at {{college_name}}.',
        ])->assertSessionHasNoErrors();
        $bonafideTemplate = \App\Models\CertificateTemplate::query()->where('type', 'bonafide')->firstOrFail();

        foreach (['bonafide', 'character'] as $type) {
            $payload = [
                'type' => $type, 'student_id' => $student->id, 'enrollment_id' => $enrollment->id,
                'issued_at' => '2026-09-27', 'purpose' => 'Official request',
            ];
            if ($type === 'bonafide') $payload['template_id'] = $bonafideTemplate->id;
            $this->asCollege($college, $admin)->post(route('certificates.generation.store'), $payload)->assertSessionHasNoErrors();
        }

        $issued = CertificateIssuance::query()->where('college_id', $college->id)->orderBy('type')->get();
        $this->assertCount(2, $issued);
        $this->assertSame(['bonafide', 'character'], $issued->pluck('type')->all());
        $this->assertDatabaseHas('certificate_issuances', ['type' => 'bonafide', 'student_id' => $student->id]);
        $this->assertDatabaseHas('certificate_issuances', ['type' => 'character', 'student_id' => $student->id]);
        $bonafide = $issued->firstWhere('type', 'bonafide');
        $this->assertStringContainsString($student->fullName(), $bonafide->rendered_content);
        $this->assertStringNotContainsString('{{student_name}}', $bonafide->rendered_content);
        $this->asCollege($college, $admin)->get(route('certificates.verification.index', ['number' => $bonafide->certificate_number]))->assertOk()->assertSee('Valid certificate');
        $this->asCollege($college, $admin)->get(route('certificates.issuance.pdf', $bonafide))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->asCollege($college, $admin)->post(route('certificates.requests.store'), [
            'type' => 'bonafide', 'student_id' => $student->id, 'purpose' => 'School record',
        ])->assertSessionHasNoErrors();
        $requestId = CertificateRequest::query()->where('student_id', $student->id)->value('id');
        $this->asCollege($college, $admin)->patch(route('certificates.requests.review', $requestId), ['status' => 'approved'])->assertSessionHasNoErrors();

        // TC remains the existing StudentTransfer record; its template and issue workflow live in Certificates.
        $this->asCollege($college, $admin)->post(route('certificates.templates.store'), [
            'type' => 'tc', 'name' => 'TC Standard', 'body' => 'Transfer for {{student_name}} · {{student_number}}.',
        ])->assertSessionHasNoErrors();
        $tcTemplate = \App\Models\CertificateTemplate::query()->where('type', 'tc')->firstOrFail();
        $this->asCollege($college, $admin)->post(route('certificates.transfer-requests.store'), [
            'student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'transfer_date' => '2026-09-01',
            'reason' => 'Moving to another institution', 'destination_institution' => 'Other College',
        ])->assertSessionHasNoErrors();
        $transfer = StudentTransfer::query()->where('student_id', $student->id)->firstOrFail();
        $this->asCollege($college, $admin)->post(route('certificates.transfer-requests.approve', $transfer))->assertSessionHasNoErrors();
        $this->asCollege($college, $admin)->post(route('certificates.transfer-requests.issue', $transfer), ['tc_issue_date' => '2026-09-27', 'template_id' => $tcTemplate->id])->assertSessionHasNoErrors();

        $transfer->refresh();
        $this->assertSame('issued', $transfer->tc_status);
        $this->assertSame('TC-2026-0001', $transfer->tc_number);
        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertSame(1, StudentTransfer::query()->where('student_id', $student->id)->count());
        $this->assertSame(2, CertificateIssuance::query()->count(), 'TC does not create a second independent issuance row.');
        $this->assertDatabaseMissing('certificate_issuances', ['type' => 'tc']);
        $this->assertDatabaseHas('student_enrollments', ['id' => $enrollment->id, 'status' => 'withdrawn']);

        $this->asCollege($college, $admin)->get(route('certificates.issuance.index'))->assertOk()->assertSee($transfer->tc_number);
        $this->asCollege($college, $admin)->get(route('certificates.verification.index', ['number' => $transfer->tc_number]))->assertOk()->assertSee('Valid certificate');
        $pdf = $this->asCollege($college, $admin)->get(route('certificates.tc.pdf', $transfer))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->asCollege($college, $admin)->get(route('certificates.reports.index'))->assertOk()->assertSee('Bonafide Certificate')->assertSee('Character Certificate');
    }

    public function test_transfer_exit_history_remains_in_student_history(): void
    {
        $college = $this->makeCollege('CERTHIST');
        $admin = $this->makeUserWithPermissions($college, $this->certificatePermissions());
        $student = $this->makeStudent($college);
        $transfer = $this->makeTransfer($college, $student, ['status' => 'approved', 'tc_status' => 'pending']);

        $this->asCollege($college, $admin)->get(route('student-history.show', $student))->assertOk()->assertSee('Transfer');
        $this->assertDatabaseHas('student_transfers', ['id' => $transfer->id, 'student_id' => $student->id]);
    }
}
