<?php

namespace Tests\Feature\Administration;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\CommunicationTemplate;
use App\Models\Department;
use App\Models\Subject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AcademicAndNotificationConfigurationTest extends TestCase
{
    use AdministrationTestHelpers;

    public function test_academic_hub_lists_existing_masters_and_isolated_active_year_without_creating_any_records(): void
    {
        $college = $this->college();
        $other = $this->college('ACADEMIC-OTHER');
        $actor = $this->actor($college, ['academic-years.view', 'departments.view', 'subjects.view']);
        AcademicYear::create(['college_id' => $college->id, 'name' => 'Own Active Session', 'code' => 'OWN', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active']);
        AcademicYear::create(['college_id' => $other->id, 'name' => 'Foreign Active Session', 'code' => 'FOREIGN', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active']);
        Department::create(['college_id' => $college->id, 'name' => 'Own Department', 'code' => 'OWN', 'status' => 'active']);
        Department::create(['college_id' => $other->id, 'name' => 'Foreign Department', 'code' => 'FOREIGN', 'status' => 'active']);
        $count = AcademicYear::withoutGlobalScopes()->count();
        $this->asCollege($college, $actor)->get(route('admin.academic-config.index'))->assertOk()->assertSee('Own Active Session')->assertSee('Own Department')->assertDontSee('Foreign Active Session')->assertDontSee('Foreign Department')->assertSee(route('departments.index'), false);
        $this->assertSame($count, AcademicYear::withoutGlobalScopes()->count());
    }

    public function test_academic_hub_cannot_broaden_resource_authority_and_counts_only_authorized_masters(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['subjects.view']);
        Subject::create(['college_id' => $college->id, 'name' => 'Authorized Subject', 'code' => 'SUB', 'status' => 'active']);
        Department::create(['college_id' => $college->id, 'name' => 'Restricted Department Label', 'code' => 'DEP', 'status' => 'active']);
        $this->asCollege($college, $actor)->get(route('admin.academic-config.index'))->assertOk()->assertSee('Authorized Subject')->assertDontSee('Restricted Department Label')->assertViewHas('configuration', fn ($cards) => count($cards) === 1 && $cards[0]['label'] === 'Subjects');
        $this->asCollege($college, $actor)->get(route('departments.index'))->assertForbidden();
        $this->asCollege($college, $actor)->get(route('subjects.create'))->assertForbidden();
        $this->asCollege($college, $this->actor($college, ['settings.view']))->get(route('admin.academic-config.index'))->assertForbidden();
    }

    public function test_notification_config_uses_existing_template_status_service_and_audit_without_external_delivery(): void
    {
        Http::fake();
        Mail::fake();
        Notification::fake();
        $college = $this->college();
        $actor = $this->actor($college, ['communication_templates.view', 'communication_templates.update']);
        $template = $this->template($college, 'OWN_TEMPLATE');
        $count = CommunicationTemplate::withoutGlobalScopes()->count();
        $this->asCollege($college, $actor)->get(route('admin.notification-settings.index'))->assertOk()->assertSee($template->name)->assertSee('definitions only');
        $this->asCollege($college, $actor)->patch(route('admin.notification-settings.templates.status', $template), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $template->refresh()->status);
        $this->assertSame($count, CommunicationTemplate::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'communication_templates.updated', 'college_id' => $college->id, 'subject_id' => $template->id]);
        $this->asCollege($college, $actor)->get(route('communication-templates.show', $template))->assertOk()->assertSee('Inactive');
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_notification_configuration_and_status_changes_cannot_disclose_or_edit_foreign_templates(): void
    {
        $college = $this->college();
        $other = $this->college('NOTIFICATION-OTHER');
        $actor = $this->actor($college, ['communication_templates.view', 'communication_templates.update']);
        $own = $this->template($college, 'OWN');
        $foreign = $this->template($other, 'FOREIGN');
        $this->asCollege($college, $actor)->get(route('admin.notification-settings.index'))->assertOk()->assertSee($own->name)->assertDontSee($foreign->name);
        $this->asCollege($college, $actor)->patch(route('admin.notification-settings.templates.status', $foreign), ['status' => 'inactive'])->assertNotFound();
        $this->asCollege($college, $this->super($college))->patch(route('admin.notification-settings.templates.status', $foreign), ['status' => 'inactive'])->assertNotFound();
        $this->assertSame('active', $foreign->refresh()->status);
        $this->asCollege($college, $actor)->patch(route('admin.notification-settings.templates.status', $own), ['status' => 'inactive', 'college_id' => $other->id, 'channel' => 'sms'])->assertSessionHasErrors(['college_id', 'channel']);
        $this->assertSame('active', $own->refresh()->status);
    }

    public function test_notification_settings_require_existing_view_and_update_permissions(): void
    {
        $college = $this->college();
        $template = $this->template($college, 'GUARDED');
        $viewer = $this->actor($college, ['communication_templates.view']);
        $settingsViewer = $this->actor($college, ['settings.view']);
        $stranger = $this->actor($college);
        $this->asCollege($college, $viewer)->get(route('admin.notification-settings.index'))->assertOk()->assertSee($template->name);
        $this->asCollege($college, $viewer)->patch(route('admin.notification-settings.templates.status', $template), ['status' => 'inactive'])->assertForbidden();
        $this->asCollege($college, $settingsViewer)->get(route('admin.notification-settings.index'))->assertOk()->assertDontSee($template->name);
        $this->asCollege($college, $settingsViewer)->patch(route('admin.notification-settings.templates.status', $template), ['status' => 'inactive'])->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('admin.notification-settings.index'))->assertForbidden();
    }

    private function template(College $college, string $code): CommunicationTemplate
    {
        return CommunicationTemplate::create(['college_id' => $college->id, 'name' => $code.' template label', 'code' => $code, 'channel' => 'email', 'subject' => 'Existing reusable definition', 'body' => 'Template body', 'status' => 'active']);
    }
}
