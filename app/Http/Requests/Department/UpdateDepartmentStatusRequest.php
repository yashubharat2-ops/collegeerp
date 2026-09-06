<?php
namespace App\Http\Requests\Department;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
class UpdateDepartmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = Department::query()->find((int) $this->route('department'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'in:active,inactive']];
    }
}
