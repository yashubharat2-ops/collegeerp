<?php
namespace Tests\Feature\Platform;
use App\Models\User;
use Tests\TestCase;
class PlatformWorkflowTest extends TestCase
{
    public function test_academic_year_requires_valid_dates(): void
    {
        $this->actingAs(User::where('email', 'test@example.com')->first())->post('/academic-years', ['name' => 'Invalid', 'code' => 'BAD', 'starts_on' => '2026-12-31', 'ends_on' => '2026-01-01', 'status' => 'active'])->assertSessionHasErrors('ends_on');
    }
}
