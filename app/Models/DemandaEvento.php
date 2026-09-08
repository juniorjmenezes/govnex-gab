<?php

namespace App\Models;

use App\Enums\DemandEventType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um item da timeline da demanda. É a única fonte de "o que aconteceu":
 * atualizações, encaminhamentos, retornos, mudanças de status/responsável/
 * prioridade/prazo e resolução/reabertura/encerramento são todos eventos
 * aqui, cada um com os metadados estruturados que fizerem sentido para o
 * seu tipo (destino/setor/prazo_esperado para encaminhamentos, `dados` json
 * para o resto).
 *
 * @property int $id
 * @property int $demanda_id
 * @property int|null $usuario_id
 * @property DemandEventType $tipo
 * @property string|null $descricao
 * @property array<string, mixed>|null $dados
 * @property string|null $destino
 * @property string|null $setor
 * @property string|null $referencia_externa
 * @property Carbon|null $prazo_esperado
 * @property Carbon|null $retorno_recebido_em
 * @property int|null $retorno_de_evento_id
 * @property Carbon|null $created_at
 */
class DemandaEvento extends TenantModel
{
    public const UPDATED_AT = null;

    protected $table = 'demanda_eventos';

    /** @return BelongsTo<Demanda, $this> */
    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DemandaAnexo, $this> */
    public function anexos(): HasMany
    {
        return $this->hasMany(DemandaAnexo::class, 'demanda_evento_id');
    }

    /** @return BelongsTo<DemandaEvento, $this> */
    public function retornoDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retorno_de_evento_id');
    }

    /** @return HasMany<DemandaEvento, $this> */
    public function retornos(): HasMany
    {
        return $this->hasMany(self::class, 'retorno_de_evento_id');
    }

    public function isReferralPending(): bool
    {
        return $this->tipo === DemandEventType::Encaminhamento && $this->retorno_recebido_em === null;
    }

    protected function casts(): array
    {
        return [
            'tipo' => DemandEventType::class,
            'dados' => 'array',
            'prazo_esperado' => 'date',
            'retorno_recebido_em' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
