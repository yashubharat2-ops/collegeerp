<?php

namespace Tests\Feature\Certificates;

use App\Models\{Certificate, CertificateType, College, User};
use App\Services\Certificates\CertificateCatalog;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class CertificateNavigationTest extends TestCase
{
    use StudentTestHelpers;

    private College $college;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->college = $this->makeCollege('NAV');
        $this->staff = $this->makeUserWithPermissions($this->college, [
            'certificates.view', 'certificate_templates.manage', 'certificate_reports.view',
        ]);
        app(TenantContext::class)->set($this->college);
        app(CertificateCatalog::class)->provision($this->college->id);
        $this->asCollege($this->college, $this->staff);
    }

    private function seedWorkflowRecords(College $college): void
    {
        app(TenantContext::class)->set($college);
        app(CertificateCatalog::class)->provision($college->id);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $this->makeYear($college));
        foreach (CertificateType::all() as $type) {
            foreach (['requested', 'generated', 'issued'] as $status) {
                Certificate::create([
                    'certificate_type_id' => $type->id, 'student_id' => $student->id,
                    'student_enrollment_id' => $enrollment->id, 'status' => $status,
                ]);
            }
        }
    }

    public function test_sidebar_has_exactly_nine_links_and_all_seven_open_their_filtered_requests(): void
    {
        $response = $this->get(route('certificates.requests.index'))->assertOk();
        preg_match('/<aside\b.*?<\/aside>/s', $response->getContent(), $sidebar);
        preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $sidebar[0], $matches, PREG_SET_ORDER);
        $links = collect($matches)
            ->filter(fn ($link) => str_starts_with(html_entity_decode($link[1]), url('/certificates')))
            ->mapWithKeys(function ($link): array {
                // The label lives in a span; the preceding Unicode icon is decorative.
                preg_match('/<span\b[^>]*>(.*?)<\/span>/s', $link[2], $label);
                $text = trim(html_entity_decode(strip_tags($label[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return [$text => html_entity_decode($link[1])];
            });
        $this->assertSame([
            ...array_column(CertificateType::BUILT_INS, 1), 'Certificate Templates', 'Certificate Reports',
        ], $links->keys()->all());
        $this->assertSame(1, substr_count($sidebar[0], 'Certificate Management (EC)'));

        foreach (CertificateType::BUILT_INS as [$code, $name]) {
            $this->assertSame(route('certificates.requests.index', ['type' => $code]), $links[$name]);
            $page = $this->get($links[$name])->assertOk()->assertViewHas('stage', 'requests');
            $page->assertViewHas('type', fn ($type) => $type->code === $code && $type->college_id === $this->college->id);
            foreach (['requests', 'generation', 'issuance', 'verification'] as $stage) {
                $page->assertSee(route('certificates.'.$stage.'.index', ['type' => $code]));
            }
        }
        $this->assertSame(route('certificates.templates.index'), $links['Certificate Templates']);
        $this->assertSame(route('certificates.reports.index'), $links['Certificate Reports']);
        $this->get($links['Certificate Templates'])->assertOk();
        $this->get($links['Certificate Reports'])->assertOk();
    }

    public function test_all_stages_filter_records_by_type_status_and_active_college(): void
    {
        $this->seedWorkflowRecords($this->college);
        $other = $this->makeCollege('FOREIGNNAV');
        $this->seedWorkflowRecords($other);
        app(TenantContext::class)->set($this->college);

        foreach (CertificateType::BUILT_INS as [$code, $name]) {
            $type = CertificateType::where('code', $code)->firstOrFail();
            foreach (['requests' => 'requested', 'generation' => 'requested', 'issuance' => 'generated', 'verification' => 'issued'] as $stage => $status) {
                $expected = Certificate::where('certificate_type_id', $type->id)->where('status', $status)->pluck('id')->all();
                $this->get(route('certificates.'.$stage.'.index', ['type' => $code]))
                    ->assertOk()->assertViewHas('stage', $stage)
                    ->assertViewHas('certificates', fn ($records) => $records->getCollection()->pluck('id')->all() === $expected);
            }
        }
    }

    public function test_route_stage_cannot_be_overridden_and_type_filter_survives_pagination(): void
    {
        $this->seedWorkflowRecords($this->college);
        $model = Certificate::whereHas('type', fn ($query) => $query->where('code', 'BON'))->where('status', 'requested')->firstOrFail();
        for ($i = 0; $i < 21; $i++) $model->replicate()->save();
        $page = $this->get(route('certificates.requests.index', ['type' => 'BON', 'stage' => 'issuance']))
            ->assertOk()->assertViewHas('stage', 'requests');
        $page->assertViewHas('certificates', fn ($records) => $records->total() === 22);
        $next = $page->viewData('certificates')->nextPageUrl();
        $this->assertStringContainsString('type=BON', $next);
        $this->get($next)->assertOk()->assertViewHas('stage', 'requests')->assertViewHas('type', fn ($type) => $type->code === 'BON');
    }

    public function test_unknown_and_other_college_only_types_are_not_visible(): void
    {
        $other = $this->makeCollege('TYPEOWNER');
        CertificateType::withoutGlobalScopes()->create([
            'college_id' => $other->id, 'code' => 'OTHER_ONLY', 'name' => 'Private type',
        ]);
        foreach (['requests', 'generation', 'issuance', 'verification'] as $stage) {
            foreach (['UNKNOWN', 'OTHER_ONLY'] as $code) {
                $this->get(route('certificates.'.$stage.'.index', ['type' => $code]))->assertNotFound();
            }
        }
        $custom = CertificateType::create(['code' => 'STUDY', 'name' => 'Study Certificate', 'description' => 'Local type']);
        foreach (['requests', 'generation', 'issuance', 'verification'] as $stage) {
            $this->get(route('certificates.'.$stage.'.index', ['type' => $custom->code]))->assertOk()->assertViewHas('type', fn ($type) => $type->id === $custom->id);
        }
    }

    public function test_named_entry_points_preserve_existing_permissions(): void
    {
        $nobody = $this->makeUserWithPermissions($this->college, []);
        $this->asCollege($this->college, $nobody);
        foreach (['requests', 'generation', 'issuance', 'verification', 'templates', 'reports'] as $page) {
            $this->get(route('certificates.'.$page.'.index', ['type' => 'TC']))->assertForbidden();
        }
        $viewer = $this->makeUserWithPermissions($this->college, ['certificates.view']);
        $this->asCollege($this->college, $viewer)->get(route('certificates.requests.index', ['type' => 'TC']))->assertOk();
        $this->get(route('certificates.templates.index'))->assertForbidden();
        $this->get(route('certificates.reports.index'))->assertForbidden();
    }
}
