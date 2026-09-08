<?php

namespace App\Models;

use App\Enums\WhatsAppMode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $entidade_id
 * @property int|null $entidade_whatsapp_conexao_id
 * @property int $gabinete_id
 * @property WhatsAppMode $modo
 * @property string $resumo_diario_em
 * @property list<string>|null $finalidades_habilitadas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsAppConfiguration extends TenantModel
{
    protected $table = 'whatsapp_configuracoes';

    /** @return BelongsTo<Entidade, $this> */
    public function entidade(): BelongsTo
    {
        return $this->belongsTo(Entidade::class, 'entidade_id');
    }

    /** @return BelongsTo<EntidadeWhatsAppConexao, $this> */
    public function conexao(): BelongsTo
    {
        return $this->belongsTo(EntidadeWhatsAppConexao::class, 'entidade_whatsapp_conexao_id');
    }

    protected function casts(): array
    {
        return [
            'modo' => WhatsAppMode::class,
            'finalidades_habilitadas' => 'array',
        ];
    }
}
