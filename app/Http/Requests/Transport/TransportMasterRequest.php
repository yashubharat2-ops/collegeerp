<?php

namespace App\Http\Requests\Transport;

use App\Models\{TransportDriver, TransportStop};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Only validated master fields leave this boundary; tenant and actors are never input. */
class TransportMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $controller = $this->route()->getController();
        $record = $controller->resolveRecord($this);

        return $this->user()->can($record ? 'update' : 'create', $record ?? $controller->model);
    }

    protected function prepareForValidation(): void
    {
        foreach (['registration_number', 'license_number', 'code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        $controller = $this->route()->getController();
        $class = $controller->model;
        $record = $controller->resolveRecord($this);
        $college = app(TenantContext::class)->require()->id;
        $table = (new $class)->getTable();
        $rules = [];
        foreach ($class::FIELDS as $field => $type) {
            $rules[$field] = match ($type) {
                'date' => ['nullable', 'date_format:Y-m-d'],
                'time' => ['nullable', 'date_format:H:i'],
                'number' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'select' => ['required', Rule::in($class::STATUSES)],
                'textarea' => ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:255'],
            };
        }
        foreach (['name', 'registration_number', 'license_number', 'code'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field] = ['required', 'string', 'max:'.($field === 'name' ? 255 : 50)];
                if ($field !== 'name') {
                    $unique = Rule::unique($table, $field)->where('college_id', $college)->ignore($record?->id);
                    if ($class === TransportStop::class) {
                        $unique->where('route_id', $controller->resolveParent($this)->id);
                    }
                    $rules[$field][] = $unique;
                }
            }
        }
        if ($class === TransportDriver::class) {
            $rules['faculty_id'] = ['required', 'integer', Rule::exists('faculties', 'id')->where('college_id', $college)->whereNull('deleted_at')];
            $rules['license_expiry'] = ['required', 'date_format:Y-m-d'];
            $rules['license_type'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }
}
