<?php
namespace App\Http\Requests\Program;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Resolve through the tenant-scoped query: foreign-college rows are 404
        // (never a distinguishable 403), then the policy enforces the permission.
        $model = Program::query()->find((int) $this->route('program'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('program');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('programs', 'name')->where('college_id', $collegeId)->ignore($ignoreId)],
            'code' => ['required', 'string', 'max:50', Rule::unique('programs', 'code')->where('college_id', $collegeId)->ignore($ignoreId)],
            'short_name' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,inactive'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('college_id', $collegeId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
    }
}
