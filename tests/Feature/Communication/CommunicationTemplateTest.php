<?php

namespace Tests\Feature\Communication;

use App\Models\AuditLog;
use App\Models\CommunicationTemplate;
use Tests\TestCase;

/**
 * Communication Management Phase 2 — SMS / Email Templates.
 *
 * Tenant isolation, RBAC, per-college code uniqueness, server-controlled
 * fields (college_id / created_by / updated_by), SMS templates without a
 * subject, filters and the audit trail.
 */
class CommunicationTemplateTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_templates_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('CTP01');
        $other = $this->makeCollege('CTP01X');
        $user = $this->makeUserWithPermissions($college, self::TEMPLATE_PERMISSIONS);

        $this->makeTemplate($college, ['name' => 'Our Own Template', 'code' => 'OURS']);
        $foreign = $this->makeTemplate($other, ['name' => 'Foreign Template', 'code' => 'THEIRS']);

        $this->asCollege($college, $user)
            ->get(route('communication-templates.index'))
            ->assertOk()
            ->assertSee('Our Own Template')
            ->assertDontSee('Foreign Template');

        foreach (['communication-templates.show', 'communication-templates.edit'] as $route) {
            $this->asCollege($college, $user)->get(route($route, $foreign))->assertNotFound();
        }

        $this->asCollege($college, $user)
            ->put(route('communication-templates.update', $foreign), $this->templatePayload(['name' => 'Hijacked']))
            ->assertNotFound();
        $this->asCollege($college, $user)->delete(route('communication-templates.destroy', $foreign))->assertNotFound();

        $foreign->refresh();
        $this->assertSame('Foreign Template', $foreign->name);

        // A forged college_id never moves a new template into another college.
        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload(['code' => 'STAMPED', 'college_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $stamped = CommunicationTemplate::withoutGlobalScopes()->where('code', 'STAMPED')->firstOrFail();
        $this->assertSame($college->id, $stamped->college_id);
        $this->assertSame($user->id, $stamped->created_by);
        $this->assertSame($user->id, $stamped->updated_by);
    }

    public function test_actions_require_the_matching_permission(): void
    {
        $college = $this->makeCollege('CTP02');
        $template = $this->makeTemplate($college, ['name' => 'Guarded Template']);

        $viewer = $this->makeUserWithPermissions($college, ['communication_templates.view']);
        $this->asCollege($college, $viewer)->get(route('communication-templates.index'))->assertOk()->assertSee('Guarded Template');
        $this->asCollege($college, $viewer)->get(route('communication-templates.show', $template))->assertOk();
        $this->asCollege($college, $viewer)->get(route('communication-templates.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('communication-templates.store'), $this->templatePayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('communication-templates.edit', $template))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('communication-templates.update', $template), $this->templatePayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('communication-templates.destroy', $template))->assertForbidden();

        $stranger = $this->makeUserWithPermissions($college, ['notices.view']);
        $this->asCollege($college, $stranger)->get(route('communication-templates.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('communication-templates.show', $template))->assertForbidden();
    }

    public function test_a_template_code_is_unique_per_college_but_reusable_across_colleges(): void
    {
        $college = $this->makeCollege('CTP03');
        $other = $this->makeCollege('CTP03X');
        $user = $this->makeUserWithPermissions($college, self::TEMPLATE_PERMISSIONS);
        $otherUser = $this->makeUserWithPermissions($other, self::TEMPLATE_PERMISSIONS);

        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload(['code' => 'FEE_DUE']))
            ->assertSessionHasNoErrors();

        // Same college, same code (case-insensitive) → rejected.
        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload(['name' => 'Second', 'code' => 'fee_due']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, CommunicationTemplate::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // Another college may use the very same code.
        $this->asCollege($other, $otherUser)
            ->post(route('communication-templates.store'), $this->templatePayload(['code' => 'FEE_DUE']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CommunicationTemplate::withoutGlobalScopes()->where('college_id', $other->id)->count());

        // Editing a template keeps its own code available…
        $template = CommunicationTemplate::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->asCollege($college, $user)
            ->put(route('communication-templates.update', $template), $this->templatePayload(['name' => 'Renamed', 'code' => 'FEE_DUE']))
            ->assertSessionHasNoErrors();

        // …but never lets it collide with a sibling.
        $sibling = $this->makeTemplate($college, ['code' => 'OTHER_CODE']);
        $this->asCollege($college, $user)
            ->put(route('communication-templates.update', $sibling), $this->templatePayload(['code' => 'FEE_DUE']))
            ->assertSessionHasErrors('code');

        $this->assertSame('OTHER_CODE', $sibling->refresh()->code);
    }

    public function test_a_template_is_created_updated_and_deleted_with_an_audit_trail(): void
    {
        $college = $this->makeCollege('CTP04');
        $user = $this->makeUserWithPermissions($college, self::TEMPLATE_PERMISSIONS);

        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload())
            ->assertRedirect();

        $template = CommunicationTemplate::withoutGlobalScopes()->where('code', 'FEE_REMINDER')->firstOrFail();
        $this->assertSame('email', $template->channel);
        $this->assertSame('active', $template->status);
        $this->assertSame('Your fee instalment is due', $template->subject);
        $this->assertSame(1, AuditLog::where('action', 'communication_templates.created')->count());

        $this->asCollege($college, $user)
            ->put(route('communication-templates.update', $template), $this->templatePayload(['name' => 'Fee reminder v2', 'status' => 'inactive']))
            ->assertRedirect();

        $template->refresh();
        $this->assertSame('Fee reminder v2', $template->name);
        $this->assertSame('inactive', $template->status);
        $this->assertSame(1, AuditLog::where('action', 'communication_templates.updated')->count());

        $this->asCollege($college, $user)->delete(route('communication-templates.destroy', $template))->assertRedirect();
        $this->assertSame(0, CommunicationTemplate::withoutGlobalScopes()->whereKey($template->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'communication_templates.deleted')->count());
    }

    public function test_sms_templates_never_store_a_subject_and_email_templates_require_one(): void
    {
        $college = $this->makeCollege('CTP05');
        $user = $this->makeUserWithPermissions($college, self::TEMPLATE_PERMISSIONS);

        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload([
                'code' => 'SMS_ALERT',
                'channel' => 'sms',
                'subject' => 'Ignored for SMS',
                'body' => 'Short alert text.',
            ]))
            ->assertSessionHasNoErrors();

        $sms = CommunicationTemplate::withoutGlobalScopes()->where('code', 'SMS_ALERT')->firstOrFail();
        $this->assertNull($sms->subject);

        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload(['code' => 'NO_SUBJECT', 'subject' => null]))
            ->assertSessionHasErrors('subject');

        // Invalid channels and statuses are rejected.
        $this->asCollege($college, $user)
            ->post(route('communication-templates.store'), $this->templatePayload(['code' => 'BAD_CHANNEL', 'channel' => 'whatsapp']))
            ->assertSessionHasErrors('channel');
    }

    public function test_the_listing_filters_by_channel_status_and_search(): void
    {
        $college = $this->makeCollege('CTP06');
        $user = $this->makeUserWithPermissions($college, ['communication_templates.view']);

        $this->makeTemplate($college, ['name' => 'Exam SMS Alert', 'code' => 'EXAM_SMS', 'channel' => 'sms', 'subject' => null]);
        $this->makeTemplate($college, ['name' => 'Fee Email Notice', 'code' => 'FEE_MAIL', 'channel' => 'email', 'status' => 'inactive']);

        $this->asCollege($college, $user)
            ->get(route('communication-templates.index', ['channel' => 'sms']))
            ->assertOk()
            ->assertSee('Exam SMS Alert')
            ->assertDontSee('Fee Email Notice');

        $this->asCollege($college, $user)
            ->get(route('communication-templates.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Fee Email Notice')
            ->assertDontSee('Exam SMS Alert');

        $this->asCollege($college, $user)
            ->get(route('communication-templates.index', ['search' => 'EXAM_SMS']))
            ->assertOk()
            ->assertSee('Exam SMS Alert')
            ->assertDontSee('Fee Email Notice');

        // A malformed filter never breaks the screen.
        $this->asCollege($college, $user)
            ->get(route('communication-templates.index', ['channel' => 'carrier-pigeon', 'status' => ['array']]))
            ->assertOk();
    }

    public function test_placeholders_are_rendered_without_touching_unknown_markers(): void
    {
        $college = $this->makeCollege('CTP07');
        $template = $this->makeTemplate($college, [
            'subject' => 'Hello {{ name }}',
            'body' => 'Dear {{ name }}, your balance is {{ amount }}.',
        ]);

        $this->assertSame('Hello Asha', $template->renderSubject(['name' => 'Asha']));
        $this->assertSame('Dear Asha, your balance is {{ amount }}.', $template->renderBody(['name' => 'Asha']));
        $this->assertSame(['name', 'amount'], $template->placeholders());
    }
}
