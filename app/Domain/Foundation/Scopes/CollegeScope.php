<?php

namespace App\Domain\Foundation\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class CollegeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $qualified = $model->qualifyColumn('college_id');
        $context->has()
            ? $builder->where($qualified, $context->id())
            : $builder->whereRaw('1 = 0');
    }
}
