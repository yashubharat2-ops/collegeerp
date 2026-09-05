<?php
namespace Tests\Feature\Tenancy;
use App\Models\{College, User};
use Tests\TestCase;
class MultiCollegeUserTest extends TestCase
{
    public function test_user_can_have_multiple_college_memberships_and_default_context(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        $second = College::create(['name' => 'Second College', 'code' => 'SECOND', 'slug' => 'second-college', 'status' => 'active']);
        $user->colleges()->attach($second->id, ['is_default' => false]);
        $this->assertCount(2, $user->fresh()->colleges);
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame($user->colleges()->wherePivot('is_default', true)->value('colleges.id'), app(\App\Support\Tenancy\TenantContext::class)->id());
    }
    public function test_super_admin_can_switch_to_another_college(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        $second = College::create(['name' => 'Switch College', 'code' => 'SWITCH', 'slug' => 'switch-college', 'status' => 'active']);
        $this->actingAs($user)->post('/college-context', ['college_id' => $second->id])->assertRedirect();
        $this->assertSame($second->id, session('active_college_id'));
    }
}
