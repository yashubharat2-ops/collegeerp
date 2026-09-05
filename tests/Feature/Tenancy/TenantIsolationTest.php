<?php
namespace Tests\Feature\Tenancy;
use App\Models\{Campus, College, User};
use Tests\TestCase;
class TenantIsolationTest extends TestCase
{
    public function test_tenant_owned_queries_are_scoped(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $other = College::create(['name' => 'Other College', 'code' => 'OTHER', 'slug' => 'other-college', 'status' => 'active']);
        Campus::withoutGlobalScopes()->create(['college_id' => $other->id, 'name' => 'Other Campus', 'code' => 'OTHER-1', 'status' => 'active']);
        $this->actingAs($user)->withSession(['active_college_id' => $user->colleges()->first()->id])->get('/campuses')->assertDontSee('Other Campus');
    }
    public function test_unauthorized_college_context_is_rejected(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $other = College::create(['name' => 'Other College', 'code' => 'OTHER2', 'slug' => 'other-college-2', 'status' => 'active']);
        $this->actingAs($user)->withSession(['active_college_id' => $other->id])->get('/dashboard')->assertForbidden();
    }
}
