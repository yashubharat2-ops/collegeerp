<?php

namespace App\Http\Requests\BulkAction;

use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteBulkActionRequest extends FormRequest
{
    /**
     * Hard upper bound on the records one POST may act on.
     *
     * Enforced here (not silently truncated): a request with more ids is rejected
     * with a validation error and no handler runs, so no partial batch is ever
     * executed or reported as successful.
     */
    public const MAX_IDS = 200;

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
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            // Malformed ids (non-numeric, decimals, nested values) are rejected
            // outright instead of being coerced or dropped.
            'ids.*' => ['required', 'integer', 'min:1'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.max' => 'You can act on at most '.self::MAX_IDS.' records at a time. Select fewer records and try again. Nothing was changed.',
            'ids.*.integer' => 'The selection contains an invalid record id. Nothing was changed.',
            'ids.*.min' => 'The selection contains an invalid record id. Nothing was changed.',
        ];
    }
}
