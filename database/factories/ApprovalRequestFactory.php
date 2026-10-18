<?php

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    public function definition(): array
    {
        return [
            'college_id' => 1,
            'module' => 'admission',
            'action' => 'application_submit',
            'target_type' => 'admission.application_submit',
            'target_id' => 1,
            'reference_number' => 'APR-' . fake()->unique()->numerify('####'),
            'title' => 'Approval for application',
            'description' => fake()->sentence(),
            'payload' => ['status' => 'approved'],
            'status' => 'pending',
            'requested_by' => 1,
        ];
    }
}
