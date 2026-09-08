<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Linha única com a configuração da GOVNEX API para toda a plataforma.
 *
 * @property int $id
 * @property string $url
 * @property string|null $chave
 * @property Carbon|null $verificada_em
 * @property string|null $verificado_resultado
 * @property string|null $verificado_detalhe
 * @property int|null $atualizado_por_id
 */
class IntegracaoGovnexApi extends Model
{
    protected $table = 'integracao_govnex_api';

    protected $fillable = [
        'url', 'chave', 'verificada_em', 'verificado_resultado',
        'verificado_detalhe', 'atualizado_por_id',
    ];

    protected $hidden = ['chave'];

    protected function casts(): array
    {
        return [
            'chave' => 'encrypted',
            'verificada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por_id')->withTrashed();
    }
}
