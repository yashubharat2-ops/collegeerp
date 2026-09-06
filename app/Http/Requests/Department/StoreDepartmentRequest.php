<?php
namespace App\Http\Requests\Department;
use App\Models\Department;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', Department::class) ?? false; }

    /**
     * college_id is never taken from the browser: the tenant is the server-side
     * context (filled by ResolveTenant) and is attached by BelongsToCollege.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('college_id', $collegeId)],
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->where('college_id', $collegeId)],
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
