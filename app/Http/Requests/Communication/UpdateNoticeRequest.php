<?php

namespace App\Http\Requests\Communication;

use App\Models\Notice;

/**
 * Update a tenant-scoped notice.
 *
 * The notice is resolved through CollegeScope, so a foreign-tenant id 404s.
 * Same rules as creation, plus an optional "remove attachment" flag.
 */
class UpdateNoticeRequest extends StoreNoticeRequest
{
    public function authorize(): bool
    {
        $notice = Notice::query()->find($this->route('notice'));

        if (! $notice) {
            abort(404);
        }

        return $this->user()?->can('update', $notice) ?? false;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }
}
