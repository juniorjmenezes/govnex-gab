<?php

namespace App\Models;

use App\Enums\AccessRole;
use App\Enums\EntityInvitationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $entidade_id
 * @property int|null $gabinete_id
 * @property string $email
 * @property AccessRole $papel_entidade
 * @property AccessRole|null $papel_gabinete
 * @property EntityInvitationStatus $status
 * @property Carbon $expira_em
 */
class EntityInvitation extends Model
{
    use HasUuids;

    protected $table = 'entidade_convites';

    protected $guarded = [];

    /** @return BelongsTo<Entity, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entidade_id');
    }

    /** @return BelongsTo<Office, $this> */
    public function gabinete(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'gabinete_id');
    }

    protected function casts(): array
    {
        return [
            'papel_entidade' => AccessRole::class,
            'papel_gabinete' => AccessRole::class,
            'status' => EntityInvitationStatus::class,
            'exige_troca_senha' => 'boolean',
            'expira_em' => 'datetime',
            'aceito_em' => 'datetime',
        ];
    }
}
