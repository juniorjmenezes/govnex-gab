<?php

namespace App\Models\Concerns;

use App\Models\Gabinete;
use App\Tenancy\GabineteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToGabinete
{
    protected static function bootBelongsToGabinete(): void
    {
        static::addGlobalScope('gabinete', function (Builder $builder): void {
            $context = app(GabineteContext::class);

            if ($context->bypassesTenantScope()) {
                return;
            }

            if ($gabineteId = $context->id()) {
                $builder->where($builder->getModel()->qualifyColumn('gabinete_id'), $gabineteId);

                return;
            }

            if ($context->hasAuthenticatedUser()) {
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function (Model $model): void {
            $context = app(GabineteContext::class);

            if ($context->id() !== null && ! $context->bypassesTenantScope()) {
                $model->setAttribute('gabinete_id', $context->requireId());
            }
        });
    }

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class);
    }
}
