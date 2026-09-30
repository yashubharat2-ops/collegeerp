<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\InstitutionalSetting;
use App\Services\Settings\InstitutionalSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstitutionSettingsTest extends TestCase
{
    use AdministrationTestHelpers;

    public function test_profile_branding_and_chrome_reuse_existing_college_and_settings_rows(): void
    {
        $college = $this->college();
        $other = $this->college('INSTITUTION-OTHER');
        $actor = $this->actor($college, ['settings.view', 'settings.update']);
        $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload())->assertSessionHasNoErrors();
        $this->assertSame('Configured Institution', $college->refresh()->name);
        $this->assertSame('office@example.org', $college->email);
        $this->assertSame('INSTITUTION-OTHER Institution', $other->refresh()->name);
        $this->assertDatabaseHas('institutional_settings', ['college_id' => $college->id, 'key' => InstitutionalSettingsService::SHORT_NAME, 'value' => 'Configured ERP', 'created_by' => $actor->id, 'is_public' => false]);
        $this->assertDatabaseHas('audit_logs', ['college_id' => $college->id, 'action' => 'institution.updated', 'subject_id' => $college->id]);
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.index'))->assertOk()->assertSee('Configured Institution')->assertSee('Configured ERP');
        $this->asCollege($college, $actor)->get(route('dashboard'))->assertOk()->assertSee('Configured ERP');
    }

    public function test_viewers_cannot_write_and_ownership_or_generic_secret_settings_are_never_accepted(): void
    {
        $college = $this->college();
        $viewer = $this->actor($college, ['settings.view']);
        $this->asCollege($college, $viewer)->get(route('admin.institution-settings.index'))->assertOk()->assertDontSee('Save institution settings');
        $this->asCollege($college, $viewer)->put(route('admin.institution-settings.update'), $this->profilePayload())->assertForbidden();
        $editor = $this->actor($college, ['settings.update']);
        $this->asCollege($college, $editor)->put(route('admin.institution-settings.update'), $this->profilePayload(['college_id' => 12345, 'status' => 'inactive']))->assertSessionHasErrors(['college_id', 'status']);
        $this->asCollege($college, $editor)->post(route('settings.update'), ['key' => 'smtp.password', 'value' => 'SecretNotSupported', 'type' => 'string'])->assertSessionHasErrors(['key', 'value', 'type']);
        $this->assertSame('ADMIN Institution', $college->refresh()->name);
        $this->assertDatabaseMissing('institutional_settings', ['key' => 'smtp.password']);
    }

    public function test_arbitrary_historical_settings_and_foreign_values_never_reach_either_settings_ui(): void
    {
        $college = $this->college();
        $other = $this->college('SECRET-OTHER');
        $actor = $this->actor($college, ['settings.view']);
        InstitutionalSetting::create(['college_id' => $college->id, 'key' => 'smtp.password', 'value' => 'HistoricalSecret', 'type' => 'string']);
        InstitutionalSetting::create(['college_id' => $other->id, 'key' => InstitutionalSettingsService::SHORT_NAME, 'value' => 'ForeignBrandingSecret', 'type' => 'string']);
        foreach (['admin.institution-settings.index', 'settings.index'] as $route) {
            $this->asCollege($college, $actor)->get(route($route))->assertOk()->assertDontSee('HistoricalSecret')->assertDontSee('smtp.password')->assertDontSee('ForeignBrandingSecret');
        }
    }

    public function test_private_logo_upload_replacement_and_removal_are_tenant_safe_and_audited(): void
    {
        Storage::fake('private');
        $college = $this->college();
        $actor = $this->actor($college, ['settings.view', 'settings.update']);
        $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload(['logo' => UploadedFile::fake()->image('logo.png', 80, 80)]))->assertSessionHasNoErrors();
        $first = InstitutionalSetting::withoutGlobalScopes()->where('college_id', $college->id)->where('key', InstitutionalSettingsService::LOGO_PATH)->firstOrFail();
        $path = $first->value;
        $this->assertStringStartsWith('colleges/'.$college->id.'/branding/', $path);
        Storage::disk('private')->assertExists($path);
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.logo'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->college('LOGO-OTHER');
        $stranger = $this->actor($other);
        $this->asCollege($other, $stranger)->get(route('admin.institution-settings.logo'))->assertNotFound();
        $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload(['logo' => UploadedFile::fake()->image('replacement.jpg', 90, 90)]))->assertSessionHasNoErrors();
        $replacement = $first->refresh()->value;
        $this->assertNotSame($path, $replacement);
        Storage::disk('private')->assertMissing($path);
        Storage::disk('private')->assertExists($replacement);
        $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload(['remove_logo' => true]))->assertSessionHasNoErrors();
        Storage::disk('private')->assertMissing($replacement);
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.logo'))->assertNotFound();
        $event = AuditLog::query()->where('action', 'institution.logo_updated')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['has_logo' => false], $event->new_values);
    }

    public function test_svg_invalid_images_oversized_dimensions_and_unsafe_stored_paths_are_rejected(): void
    {
        Storage::fake('private');
        $college = $this->college();
        $actor = $this->actor($college, ['settings.view', 'settings.update']);
        foreach ([
            UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('script.jpg', 1, 'application/x-php'),
            UploadedFile::fake()->image('large.png', 2001, 1),
        ] as $file) {
            $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload(['logo' => $file]))->assertSessionHasErrors('logo');
        }
        InstitutionalSetting::create(['college_id' => $college->id, 'key' => InstitutionalSettingsService::LOGO_PATH, 'value' => '../unrelated-private-file.png']);
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.logo'))->assertNotFound();
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.index'))->assertOk()->assertDontSee('../unrelated-private-file.png', false);
    }

    public function test_omitted_branding_is_preserved_and_setting_creator_is_not_overwritten(): void
    {
        $college = $this->college();
        $creator = $this->actor($college);
        $editor = $this->actor($college, ['settings.update']);
        $setting = InstitutionalSetting::create(['college_id' => $college->id, 'key' => InstitutionalSettingsService::SHORT_NAME, 'value' => 'Retained Brand', 'created_by' => $creator->id]);
        $this->asCollege($college, $editor)->put(route('admin.institution-settings.update'), ['name' => 'Contact-only change'])->assertSessionHasNoErrors();
        $this->assertSame('Retained Brand', $setting->refresh()->value);
        $this->asCollege($college, $editor)->put(route('admin.institution-settings.update'), $this->profilePayload())->assertSessionHasNoErrors();
        $this->assertSame($creator->id, $setting->refresh()->created_by);
        $this->assertSame($editor->id, $setting->updated_by);
    }

    public function test_institution_text_is_escaped_in_forms_and_application_branding(): void
    {
        $college = $this->college();
        $actor = $this->actor($college, ['settings.view', 'settings.update']);
        $value = '<script>alert("branding")</script>';
        $this->asCollege($college, $actor)->put(route('admin.institution-settings.update'), $this->profilePayload(['short_name' => $value]))->assertSessionHasNoErrors();
        $this->asCollege($college, $actor)->get(route('admin.institution-settings.index'))->assertOk()->assertSee(e($value), false)->assertDontSee($value, false);
    }
}
