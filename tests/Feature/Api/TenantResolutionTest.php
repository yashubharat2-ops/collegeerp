<?php
namespace Tests\Feature\Api;
use App\Models\User;
use Tests\TestCase;
class TenantResolutionTest extends TestCase
{
    public function test_authenticated_api_request_resolves_active_college(): void
    {
        $user = User::first();
        $this->actingAs($user)->withSession(['active_college_id' => $user->colleges()->first()->id])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
