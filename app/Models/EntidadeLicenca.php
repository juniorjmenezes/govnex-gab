<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entidade_id
 * @property LicenseStatus $status
 * @property Carbon|null $inicio_em
 * @property Carbon|null $fim_em
 * @property array<string, int|null> $cotas_snapshot
 * @property array<string, mixed>|null $ajustes
 */
class EntidadeLicenca extends Model
{
    protected $table = 'entidade_licencas';

    protected $guarded = [];

    public function permitsNewActions(): bool
    {
        return $this->status->permitsNewActions()
            && ($this->inicio_em === null || ! $this->inicio_em->isFuture())
            && ($this->fim_em === null || $this->fim_em->isFuture());
    }

    /** @return array<string, int|null> */
    public function effectiveQuotas(): array
    {
        return array_replace($this->cotas_snapshot ?? [], $this->ajustes['cotas'] ?? []);
    }

    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'inicio_em' => 'datetime',
            'fim_em' => 'datetime',
            'cotas_snapshot' => 'array',
            'ajustes' => 'array',
        ];
    }
}
