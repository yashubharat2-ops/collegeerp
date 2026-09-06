<?php
namespace App\Http\Requests\Department;
use App\Models\Department;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Resolve through the tenant-scoped query: foreign-college rows are 404
        // (never a distinguishable 403), then the policy enforces the permission.
        $model = Department::query()->find((int) $this->route('department'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('college_id', $collegeId)->ignore($ignoreId)],
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->where('college_id', $collegeId)->ignore($ignoreId)],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('college_id', $collegeId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
    }
}
