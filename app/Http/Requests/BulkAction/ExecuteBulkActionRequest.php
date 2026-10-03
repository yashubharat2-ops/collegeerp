<?php

namespace App\Http\Requests\BulkAction;

use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteBulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(BulkActionRegistry $registry): array
    {
        $module = (string) $this->input('module');
        $allowedActions = array_keys($registry->getForModule($module));

        return [
            'module' => ['required', 'string', 'max:50'],
            'action' => ['required', 'string', 'max:50', Rule::in($allowedActions)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
            'parameters' => ['nullable', 'array'],
        ];
    }
}
