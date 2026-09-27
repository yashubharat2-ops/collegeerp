<?php

namespace Tests\Feature\Certificates;

use App\Models\{Certificate, CertificateTemplate, CertificateType, College, StudentEnrollment, User};
use App\Services\Certificates\CertificateCatalog;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CertificateManagementSeeder;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class CertificateManagementTest extends TestCase
{
    use StudentTestHelpers;

    private College $college;
    private User $staff;
    private StudentEnrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->college = $this->makeCollege('CERT');
        $this->staff = $this->makeUserWithPermissions($this->college, [...CertificateManagementSeeder::PERMISSIONS, 'student_transfers.approve']);
        app(TenantContext::class)->set($this->college);
        app(CertificateCatalog::class)->provision($this->college->id);
        $student = $this->makeStudent($this->college);
        $this->enrollment = $this->makeEnrollment($this->college, $student, $this->makeYear($this->college), $this->makeProgram($this->college));
        $this->asCollege($this->college, $this->staff);
    }

    private function template(CertificateType $type, string $body = '{{ student_name }} / {{ enrollment_number }} / {{ certificate_number }}'): CertificateTemplate
    {
        return CertificateTemplate::create(['certificate_type_id' => $type->id, 'name' => 'College approved', 'body' => $body]);
    }

    private function requestCertificate(CertificateType $type, array $extra = []): Certificate
    {
        $this->post(route('certificates.store'), $extra + [
            'certificate_type_id' => $type->id, 'student_enrollment_id' => $this->enrollment->id, 'purpose' => 'Application',
        ])->assertSessionHasNoErrors()->assertRedirect();
        return Certificate::latest('id')->firstOrFail();
    }

    public function test_exact_seven_built_ins_are_provisioned_idempotently_and_visible_under_one_menu(): void
    {
        app(CertificateCatalog::class)->provision($this->college->id);
        $this->assertSame(7, CertificateType::count());
        $response = $this->get(route('certificates.index'))->assertOk();
        foreach (CertificateType::BUILT_INS as [$code, $name]) {
            $response->assertSee($name)->assertSee(route('certificates.index', ['type' => $code, 'stage' => 'verification']));
        }
        $this->assertSame(1, substr_count($response->getContent(), '>CERTIFICATE MANAGEMENT (EC)</div>'));
        $response->assertSee('Certificate Templates')->assertSee('Certificate Reports');
        $this->get(route('certificates.templates'))->assertOk();
        $this->get(route('certificates.types'))->assertOk();
        $this->get(route('certificates.reports'))->assertOk();
    }

    public function test_all_seven_types_follow_the_same_workflow_and_tc_preserves_transfer_integration(): void
    {
        foreach (CertificateType::orderBy('id')->get() as $type) {
            $extra = [];
            if ($type->builtin_key === 'transfer') {
                $transfer = $this->makeTransfer($this->college, $this->enrollment->student, [
                    'enrollment_id' => $this->enrollment->id, 'status' => 'approved',
                ]);
                $extra['student_transfer_id'] = $transfer->id;
            }
            $template = $this->template($type);
            $certificate = $this->requestCertificate($type, $extra);
            $this->assertSame('requested', $certificate->status);
            $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $template->id])->assertSessionHasNoErrors();
            $this->assertSame('generated', $certificate->fresh()->status);
            $this->post(route('certificates.issue', $certificate))->assertSessionHasNoErrors();
            $certificate->refresh();
            $this->assertSame('issued', $certificate->status);
            $this->assertNotEmpty($certificate->number);
            $this->post(route('certificates.verify'), ['number' => $certificate->number])->assertOk()->assertSee('Verified');
            $this->assertSame(1, $certificate->fresh()->verification_count);
            $this->get(route('certificates.show', $certificate))->assertOk()->assertSee($certificate->number);
            $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.verified', 'subject_id' => $certificate->id, 'college_id' => $this->college->id]);
            if ($type->builtin_key === 'transfer') {
                $this->assertSame($transfer->fresh()->tc_number, $certificate->number);
                $this->assertSame('withdrawn', $this->enrollment->fresh()->status);
                $this->assertSame('withdrawn', $this->enrollment->student->fresh()->status);
                $this->assertNotNull($this->enrollment->student->fresh());
            }
        }
    }

    public function test_admin_defined_type_supports_multiple_templates_and_full_workflow_without_code_changes(): void
    {
        $this->post(route('certificates.types.store'), ['name' => 'Study Certificate', 'code' => 'study', 'description' => 'College-specific proof of study'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $type = CertificateType::where('code', 'STUDY')->firstOrFail();
        $this->assertNull($type->builtin_key);
        foreach (['Standard', 'Scholarship application'] as $name) {
            $this->post(route('certificates.templates.store'), ['certificate_type_id' => $type->id, 'name' => $name, 'body' => '{{ student_name }} studies {{ program_name }} during {{ academic_year }}.'])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $type->templates()->count());
        $certificate = $this->requestCertificate($type);
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $type->templates()->first()->id])->assertSessionHasNoErrors();
        $this->post(route('certificates.issue', $certificate))->assertSessionHasNoErrors();
        $this->post(route('certificates.verify'), ['number' => $certificate->fresh()->number])->assertOk();
        $this->assertSame('active', $this->enrollment->fresh()->status);
        $this->assertSame('active', $this->enrollment->student->fresh()->status);
        $this->get(route('certificates.index'))->assertSee('Study Certificate');
    }

    public function test_generation_snapshots_data_and_escapes_markup_and_never_evaluates_blade(): void
    {
        $type = CertificateType::where('code', 'BON')->firstOrFail();
        $template = $this->template($type, '{{ student_name }} <script>alert(1)</script> @php echo "unsafe"; @endphp');
        $certificate = $this->requestCertificate($type);
        $name = $this->enrollment->student->fullName();
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $template->id])->assertSessionHasNoErrors();
        $template->update(['body' => 'Changed template']);
        $this->enrollment->student->update(['first_name' => 'Changed student']);
        $this->get(route('certificates.show', $certificate))->assertOk()->assertSee($name)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Changed template');
        $this->assertStringContainsString('@php', $certificate->fresh()->renderedBody());
    }

    public function test_out_of_order_and_duplicate_transitions_are_rejected_and_numbers_are_unique(): void
    {
        $type = CertificateType::where('code', 'BON')->firstOrFail();
        $template = $this->template($type);
        $first = $this->requestCertificate($type);
        $this->post(route('certificates.issue', $first))->assertSessionHasErrors('certificate');
        $this->post(route('certificates.generate', $first), ['certificate_template_id' => $template->id])->assertSessionHasNoErrors();
        $this->post(route('certificates.generate', $first), ['certificate_template_id' => $template->id])->assertSessionHasErrors('certificate');
        $this->post(route('certificates.issue', $first))->assertSessionHasNoErrors();
        $number = $first->fresh()->number;
        $this->post(route('certificates.issue', $first))->assertSessionHasErrors('certificate');
        $this->assertSame($number, $first->fresh()->number);
        $second = $this->requestCertificate($type);
        $this->post(route('certificates.generate', $second), ['certificate_template_id' => $template->id]);
        $this->post(route('certificates.issue', $second))->assertSessionHasNoErrors();
        $this->assertNotSame($number, $second->fresh()->number);
        $this->post(route('certificates.verify'), ['number' => 'NOT-ISSUED'])->assertNotFound();
        $this->post(route('certificates.verify'), ['number' => $number])->assertOk();
        $this->post(route('certificates.verify'), ['number' => $number])->assertOk();
        $this->assertSame(2, $first->fresh()->verification_count);
    }

    public function test_cross_college_records_cannot_be_read_linked_generated_issued_or_verified(): void
    {
        $type = CertificateType::where('code', 'BON')->firstOrFail();
        $template = $this->template($type);
        $certificate = $this->requestCertificate($type);
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $template->id]);
        $this->post(route('certificates.issue', $certificate));
        $number = $certificate->fresh()->number;
        $other = $this->makeCollege('OTHER');
        app(CertificateCatalog::class)->provision($other->id);
        $otherStaff = $this->makeUserWithPermissions($other, CertificateManagementSeeder::PERMISSIONS);
        $this->asCollege($other, $otherStaff);
        $this->get(route('certificates.show', $certificate))->assertNotFound();
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $template->id])->assertNotFound();
        $this->post(route('certificates.issue', $certificate))->assertNotFound();
        $this->post(route('certificates.verify'), ['number' => $number])->assertNotFound();
        $this->post(route('certificates.store'), ['certificate_type_id' => $type->id, 'student_enrollment_id' => $this->enrollment->id])->assertNotFound();
        $this->post(route('certificates.templates.store'), ['certificate_type_id' => $type->id, 'name' => 'Foreign', 'body' => 'No'])->assertNotFound();
        $this->get(route('certificates.reports'))->assertOk()->assertDontSee($number);
        $this->assertDatabaseHas('certificates', ['id' => $certificate->id, 'verification_count' => 0]);
    }

    public function test_type_and_enrollment_and_template_must_all_belong_to_active_college(): void
    {
        $type = CertificateType::where('code', 'BON')->firstOrFail();
        $other = $this->makeCollege('MIX');
        $student = $this->makeStudent($other);
        $enrollment = $this->makeEnrollment($other, $student, $this->makeYear($other));
        $this->post(route('certificates.store'), ['certificate_type_id' => $type->id, 'student_enrollment_id' => $enrollment->id])->assertNotFound();
        $certificate = $this->requestCertificate($type);
        $wrongTypeTemplate = $this->template(CertificateType::where('code', 'CHAR')->firstOrFail());
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $wrongTypeTemplate->id])->assertNotFound();
        $foreignTemplate = CertificateTemplate::withoutGlobalScopes()->create(['college_id' => $other->id, 'certificate_type_id' => $type->id, 'name' => 'Foreign', 'body' => 'Hidden']);
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $foreignTemplate->id])->assertNotFound();
    }

    public function test_tc_requires_matching_approved_transfer_and_does_not_issue_legacy_tc_twice(): void
    {
        $type = CertificateType::where('code', 'TC')->firstOrFail();
        $this->post(route('certificates.store'), ['certificate_type_id' => $type->id, 'student_enrollment_id' => $this->enrollment->id])->assertSessionHasErrors('certificate');
        $transfer = $this->makeTransfer($this->college, $this->enrollment->student, ['enrollment_id' => $this->enrollment->id, 'status' => 'approved', 'tc_status' => 'issued', 'tc_number' => 'TC-2025-0042', 'tc_issue_date' => '2025-07-01']);
        $certificate = $this->requestCertificate($type, ['student_transfer_id' => $transfer->id]);
        $this->post(route('certificates.store'), ['certificate_type_id' => $type->id, 'student_enrollment_id' => $this->enrollment->id, 'student_transfer_id' => $transfer->id])->assertSessionHasErrors('certificate');
        $this->post(route('certificates.generate', $certificate), ['certificate_template_id' => $this->template($type)->id]);
        $this->post(route('certificates.issue', $certificate))->assertSessionHasNoErrors();
        $this->assertSame('TC-2025-0042', $certificate->fresh()->number);
        $this->assertSame('2025-07-01', $certificate->fresh()->issued_at->toDateString());
    }

    public function test_permissions_are_enforced_on_every_endpoint(): void
    {
        $certificate = $this->requestCertificate(CertificateType::where('code', 'BON')->firstOrFail());
        $nobody = $this->makeUserWithPermissions($this->college, []);
        $this->asCollege($this->college, $nobody);
        foreach (['index', 'types', 'templates', 'reports'] as $page) $this->get(route('certificates.'.$page))->assertForbidden();
        $this->get(route('certificates.show', $certificate))->assertForbidden();
        foreach (['store', 'types.store', 'templates.store', 'verify'] as $action) $this->post(route('certificates.'.$action), [])->assertForbidden();
        foreach (['generate', 'issue'] as $action) $this->post(route('certificates.'.$action, $certificate), [])->assertForbidden();
        $viewer = $this->makeUserWithPermissions($this->college, ['certificates.view']);
        $this->asCollege($this->college, $viewer)->get(route('certificates.index'))->assertOk();
        $this->post(route('certificates.types.store'), [])->assertForbidden();
        $this->post(route('certificates.issue', $certificate))->assertForbidden();
    }

    public function test_codes_are_unique_per_college_reserved_and_templates_validate_placeholders(): void
    {
        $this->post(route('certificates.types.store'), ['name' => 'Duplicate', 'code' => 'tc', 'description' => 'No'])->assertSessionHasErrors('code');
        $this->post(route('certificates.types.store'), ['name' => 'Bad', 'code' => 'bad/code', 'description' => 'No'])->assertSessionHasErrors('code');
        $this->post(route('certificates.templates.store'), ['certificate_type_id' => CertificateType::first()->id, 'name' => 'Unsafe', 'body' => '{{ config("app.key") }}'])->assertSessionHasErrors('body');
        $this->assertSame(7, CertificateType::count());
    }
}
