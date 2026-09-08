<?php

namespace App\Models\Scopes;

use App\Models\Gabinete;
use App\Tenancy\GabineteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Gabinete> */
class GabineteVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(GabineteContext::class);

        if ($context->bypassesTenantScope()) {
            return;
        }

        if ($gabineteId = $context->id()) {
            $builder->where($model->qualifyColumn('id'), $gabineteId);

            return;
        }

        if ($context->hasAuthenticatedUser()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
