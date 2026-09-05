<?php
namespace Tests\Feature\Platform;
use App\Models\{AcademicYear, User};
use Tests\TestCase;
class AcademicYearConstraintTest extends TestCase
{
    public function test_overlapping_academic_year_is_rejected(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        $this->actingAs($user)->post('/academic-years', ['name' => '2026-27', 'code' => 'AY26', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active'])->assertRedirect();
        $this->actingAs($user)->post('/academic-years', ['name' => 'Overlap', 'code' => 'OVERLAP', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'inactive'])->assertSessionHasErrors('starts_on');
        $this->assertSame(1, AcademicYear::count());
    }
}
