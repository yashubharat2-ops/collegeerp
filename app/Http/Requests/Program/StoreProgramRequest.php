<?php
namespace App\Http\Requests\Program;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', Program::class) ?? false; }

    /**
     * college_id is never taken from the browser: the tenant is the server-side
     * context (filled by ResolveTenant) and is attached by BelongsToCollege.
     * department_id must reference a department of the current college; a
     * foreign-college id simply "does not exist" here, so nothing leaks.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('programs', 'name')->where('college_id', $collegeId)],
            'code' => ['required', 'string', 'max:50', Rule::unique('programs', 'code')->where('college_id', $collegeId)],
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
