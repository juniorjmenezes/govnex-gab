<?php

namespace App\Models;

use App\Enums\WhatsAppPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $entidade_id
 * @property int|null $entidade_whatsapp_conexao_id
 * @property WhatsAppPurpose $finalidade
 * @property int|null $gateway_template_id
 * @property string $nome_meta
 * @property string $idioma
 * @property string $categoria
 * @property string $status
 * @property list<array<string, mixed>>|null $componentes
 * @property string $contrato_hash
 * @property bool $ativo
 * @property Carbon|null $sincronizado_em
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsAppTemplatePurpose extends Model
{
    protected $table = 'whatsapp_template_finalidades';

    protected $guarded = [];

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

    public function isReady(): bool
    {
        return $this->ativo
            && $this->status === 'APPROVED'
            && (int) $this->gateway_template_id > 0;
    }

    protected function casts(): array
    {
        return [
            'finalidade' => WhatsAppPurpose::class,
            'componentes' => 'array',
            'ativo' => 'boolean',
            'sincronizado_em' => 'datetime',
        ];
    }
}
