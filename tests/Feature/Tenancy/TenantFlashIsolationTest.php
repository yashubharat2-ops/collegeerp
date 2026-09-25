<?php

namespace Tests\Feature\Tenancy;

use App\Models\College;
use App\Models\User;
use App\Support\Tenancy\TenantFlash;
use Tests\TestCase;

/**
 * One-shot session data belongs to the tenant that produced it.
 *
 * The session is per user, not per college, so a notification composed from one
 * college's records outlives the request that created it and would be rendered
 * on the next page the same user opens in another college — the stock ledger of
 * college A carrying a line about an item that only exists in college B. These
 * tests pin the boundary: pending one-shot data is dropped when a request is
 * served under a different college, survives within the same one, and the
 * authorized switch itself keeps its own confirmation.
 */
class TenantFlashIsolationTest extends TestCase
{
    private function otherCollege(string $code): College
    {
        return College::create([
            'name' => "{$code} College",
            'code' => $code,
            'slug' => strtolower($code).'-college',
            'status' => 'active',
        ]);
    }

    public function test_a_notification_from_one_college_is_not_rendered_under_another(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $mine = $user->colleges()->firstOrFail();
        $other = $this->otherCollege('TFLASH1');
        $user->colleges()->attach($other->id);

        // Served under the college that wrote it: shown, and that college is
        // recorded as the owner of the pending notification.
        $this->actingAs($user)
            ->withSession(['active_college_id' => $mine->id, 'success' => '5.00 pcs added to "Theirs Only".'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Theirs Only');

        // The same session, now served under another college: the notification
        // quotes records that do not belong to it, so it is not rendered.
        $this->actingAs($user)
            ->withSession(['active_college_id' => $other->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Theirs Only');

        // A notification written under the college it is read in still shows.
        $this->actingAs($user)
            ->withSession(['active_college_id' => $other->id, 'success' => '5.00 pcs added to "Mine Only".'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Mine Only');
    }

    public function test_stale_one_shot_data_is_dropped_but_session_state_is_not(): void
    {
        $session = app('session')->driver();
        $session->start();

        $session->put('success', '5.00 pcs added to "Theirs Only".');
        $session->put('errors', 'a bag of field errors');
        $session->put('_old_input', ['quantity' => '5']);
        $session->put('active_college_id', 2);

        // Nothing has been recorded yet, so the first request keeps what is there.
        TenantFlash::forgetStale($session, 1);
        $this->assertSame('5.00 pcs added to "Theirs Only".', $session->get('success'));

        // A different college: the pending one-shot data goes, and everything
        // that is not one-shot — the selected college, the CSRF token — stays.
        $token = $session->get('_token');
        TenantFlash::forgetStale($session, 2);

        $this->assertNull($session->get('success'));
        $this->assertNull($session->get('errors'));
        $this->assertNull($session->get('_old_input'));
        $this->assertSame(2, $session->get('active_college_id'));
        $this->assertSame($token, $session->get('_token'));
        $this->assertSame(2, (int) $session->get(TenantFlash::TENANT_KEY));

        // Same college again: a fresh notification is left alone.
        $session->put('success', '5.00 pcs added to "Mine Only".');
        TenantFlash::forgetStale($session, 2);
        $this->assertSame('5.00 pcs added to "Mine Only".', $session->get('success'));
    }

    public function test_an_authorized_college_switch_keeps_its_own_confirmation(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $mine = $user->colleges()->firstOrFail();
        $other = $this->otherCollege('TFLASH2');
        $user->colleges()->attach($other->id);

        // The switch is the authorized tenant boundary: its confirmation belongs
        // to the college being entered, not to the one being left.
        $this->actingAs($user)
            ->withSession(['active_college_id' => $mine->id])
            ->post(route('college-context.switch'), ['college_id' => $other->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'College context switched.');

        $this->actingAs($user)
            ->withSession(['active_college_id' => $other->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('College context switched.');
    }
}
