<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function target(): User
    {
        return app(UserAdministrationService::class)->find($this->route('user'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->target()) ?? false;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean'], 'college_id' => ['prohibited']];
    }
}
