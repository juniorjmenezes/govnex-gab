<?php

namespace App\Models\Concerns;

use App\Models\Office;
use App\Tenancy\OfficeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToOffice
{
    protected static function bootBelongsToOffice(): void
    {
        static::addGlobalScope('gabinete', function (Builder $builder): void {
            $context = app(OfficeContext::class);

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
            $context = app(OfficeContext::class);

            if ($context->id() !== null && ! $context->bypassesTenantScope()) {
                $model->setAttribute('gabinete_id', $context->requireId());
            }
        });
    }

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
