<?php
namespace Tests\Feature\Authorization;
use App\Models\{College, Role, User};
use Tests\TestCase;
class CollegeAdminRestrictionTest extends TestCase
{
    public function test_college_scoped_user_cannot_switch_to_unassigned_college(): void
    {
        $college = College::where('code', 'DEMO')->first();
        $other = College::create(['name' => 'Restricted College', 'code' => 'RESTRICTED', 'slug' => 'restricted-college', 'status' => 'active']);
        $role = Role::where('slug', 'college-admin')->where('college_id', $college->id)->first();
        $user = User::factory()->create(['email' => 'college-admin@example.com']);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);
        $this->actingAs($user)->post('/college-context', ['college_id' => $other->id])->assertForbidden();
    }
}
