<?php

namespace App\Http\Requests\Communication;

use App\Models\Circular;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Update a tenant-scoped circular. Resolved through CollegeScope, so a
 * foreign-tenant id 404s; the number stays unique among the college's other
 * circulars (archived ones included).
 */
class UpdateCircularRequest extends StoreCircularRequest
{
    private ?Circular $circular = null;

    public function authorize(): bool
    {
        $circular = $this->circular();

        if (! $circular) {
            abort(404);
        }

        return $this->user()?->can('update', $circular) ?? false;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }

    protected function uniqueNumberRule(): Unique
    {
        return Rule::unique('circulars', 'circular_number')
            ->where('college_id', (int) app(TenantContext::class)->id())
            ->ignore($this->circular()?->getKey());
    }

    private function circular(): ?Circular
    {
        return $this->circular ??= Circular::query()->find($this->route('circular'));
    }
}
