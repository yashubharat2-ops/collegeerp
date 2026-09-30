<?php

namespace App\Http\Requests\Settings;

use App\Models\InstitutionalSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Structured safe settings, including the legacy /settings write URL. */
class UpdateInstitutionalSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', InstitutionalSetting::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', Rule::dimensions()->maxWidth(2000)->maxHeight(2000)],
            'remove_logo' => ['sometimes', 'boolean'],
            'college_id' => ['prohibited'], 'code' => ['prohibited'], 'slug' => ['prohibited'], 'status' => ['prohibited'],
            'key' => ['prohibited'], 'value' => ['prohibited'], 'type' => ['prohibited'], 'is_public' => ['prohibited'],
        ];
    }
}
