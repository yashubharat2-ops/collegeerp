<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkExistingUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('linkExisting', User::class) ?? false;
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', Rule::exists('users', 'id')], 'college_id' => ['prohibited']];
    }
}
