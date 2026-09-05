<?php

namespace App\Domain\Foundation\Traits;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCollege
{
    protected static function bootBelongsToCollege(): void
    {
        static::addGlobalScope(new CollegeScope());
        static::creating(function ($model): void {
            if (! $model->college_id && app(\App\Support\Tenancy\TenantContext::class)->has()) {
                $model->college_id = app(\App\Support\Tenancy\TenantContext::class)->id();
            }
        });
    }

    public function college(): BelongsTo { return $this->belongsTo(College::class); }
}
