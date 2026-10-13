<?php

namespace App\Http\Requests\AcademicYear;

use App\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's year 404s here, not 403s.
        $model = AcademicYear::query()->find((int) $this->route('academic_year'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * The same field set and value domain as creation — `active`/`inactive` are
     * the only statuses the column, the create form and the single-active-year
     * rule already understand, so no new value is introduced here.
     *
     * `college_id` and the audit stamps are never accepted from the browser.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('academic_year');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('academic_years', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    /**
     * The no-overlap rule creation enforces, re-applied to the edited dates.
     *
     * The year being edited must be excluded from its own overlap check, or
     * saving any year without changing its dates would always fail.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $collegeId = app(TenantContext::class)->id();
            $ignoreId = (int) $this->route('academic_year');

            if (! $collegeId || ! $this->starts_on || ! $this->ends_on) {
                return;
            }

            $overlap = AcademicYear::query()
                ->where('college_id', $collegeId)
                ->whereKeyNot($ignoreId)
                ->where(function ($query): void {
                    $query->whereBetween('starts_on', [$this->starts_on, $this->ends_on])
                        ->orWhereBetween('ends_on', [$this->starts_on, $this->ends_on])
                        ->orWhere(function ($q): void {
                            $q->where('starts_on', '<=', $this->starts_on)
                                ->where('ends_on', '>=', $this->ends_on);
                        });
                })
                ->exists();

            if ($overlap) {
                $validator->errors()->add('starts_on', 'Academic years cannot overlap for a college.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
