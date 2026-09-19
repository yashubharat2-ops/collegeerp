<?php

namespace App\Http\Requests\Campus;

use App\Models\Campus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Resolve through the tenant-scoped query: foreign-college rows are 404
        // (never a distinguishable 403), then the policy enforces the permission.
        $model = Campus::query()->find((int) $this->route('campus'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('campus');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('campuses', 'name')->where('college_id', $collegeId)->whereNull('deleted_at')->ignore($ignoreId)],
            'code' => ['required', 'string', 'max:50', Rule::unique('campuses', 'code')->where('college_id', $collegeId)->whereNull('deleted_at')->ignore($ignoreId)],
            'short_name' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
