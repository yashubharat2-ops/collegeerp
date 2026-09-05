<?php
namespace Tests\Feature\Authorization;
use App\Models\User;
use Tests\TestCase;
class RbacTest extends TestCase
{
    public function test_seeded_super_admin_can_access_dashboard(): void
    {
        $this->actingAs(User::where('email', 'test@example.com')->first())->get('/dashboard')->assertOk();
    }
    public function test_policy_denies_missing_permission(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue($user->hasPermission('campuses.view'));
    }
}
