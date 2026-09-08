<?php

namespace App\Models;

use App\Enums\EntidadeInvitationStatus;
use App\Enums\EntidadeRole;
use App\Enums\GabineteRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $entidade_id
 * @property int|null $gabinete_id
 * @property string $email
 * @property EntidadeRole $papel_entidade
 * @property GabineteRole|null $papel_gabinete
 * @property EntidadeInvitationStatus $status
 * @property Carbon $expira_em
 */
class EntidadeConvite extends Model
{
    use HasUuids;

    protected $table = 'entidade_convites';

    protected $guarded = [];

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
    }

    /** @return BelongsTo<Gabinete, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Gabinete::class, 'gabinete_id');
    }

    protected function casts(): array
    {
        return [
            'papel_entidade' => EntidadeRole::class,
            'papel_gabinete' => GabineteRole::class,
            'status' => EntidadeInvitationStatus::class,
            'exige_troca_senha' => 'boolean',
            'expira_em' => 'datetime',
            'aceito_em' => 'datetime',
        ];
    }
}
