<?php

namespace App\Models;

use App\Enums\WhatsAppNotificationStatus;
use App\Enums\WhatsAppPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $entidade_id
 * @property int|null $entidade_whatsapp_conexao_id
 * @property int $gabinete_id
 * @property int|null $whatsapp_contato_id
 * @property int|null $whatsapp_template_finalidade_id
 * @property string $client_request_id
 * @property string $idempotency_key
 * @property WhatsAppPurpose $finalidade
 * @property string|null $origem_type
 * @property int|null $origem_id
 * @property string|null $telefone_criptografado
 * @property string $telefone_hash
 * @property string $telefone_final
 * @property list<string>|null $parametros_corpo_criptografados
 * @property list<array<string, mixed>>|null $parametros_botoes_criptografados
 * @property WhatsAppNotificationStatus $status
 * @property string|null $gateway_status
 * @property int $tentativas
 * @property Carbon|null $proxima_tentativa_em
 * @property Carbon $expira_em
 * @property Carbon|null $submetido_em
 * @property Carbon|null $enviado_em
 * @property Carbon|null $entregue_em
 * @property Carbon|null $lido_em
 * @property Carbon|null $falhou_em
 * @property Carbon|null $consumo_reservado_em
 * @property Carbon|null $consumo_registrado_em
 * @property Carbon|null $expurgar_sensiveis_em
 * @property string|null $erro
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppContact|null $contact
 * @property-read WhatsAppTemplatePurpose|null $templatePurpose
 * @property-read EntidadeWhatsAppConexao|null $connection
 */
class WhatsAppNotification extends TenantModel
{
    protected $table = 'whatsapp_notificacoes';

    protected $hidden = [
        'telefone_criptografado',
        'telefone_hash',
        'parametros_corpo_criptografados',
        'parametros_botoes_criptografados',
    ];

    /** @return BelongsTo<WhatsAppContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contato_id');
    }

    /** @return BelongsTo<WhatsAppTemplatePurpose, $this> */
    public function templatePurpose(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplatePurpose::class, 'whatsapp_template_finalidade_id');
    }

    /** @return BelongsTo<EntidadeWhatsAppConexao, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(EntidadeWhatsAppConexao::class, 'entidade_whatsapp_conexao_id');
    }

    /** @return MorphTo<Model, $this> */
    public function origin(): MorphTo
    {
        return $this->morphTo('origem');
    }

    protected function casts(): array
    {
        return [
            'finalidade' => WhatsAppPurpose::class,
            'telefone_criptografado' => 'encrypted',
            'parametros_corpo_criptografados' => 'encrypted:array',
            'parametros_botoes_criptografados' => 'encrypted:array',
            'status' => WhatsAppNotificationStatus::class,
            'proxima_tentativa_em' => 'datetime',
            'expira_em' => 'datetime',
            'submetido_em' => 'datetime',
            'enviado_em' => 'datetime',
            'entregue_em' => 'datetime',
            'lido_em' => 'datetime',
            'falhou_em' => 'datetime',
            'consumo_reservado_em' => 'datetime',
            'consumo_registrado_em' => 'datetime',
            'expurgar_sensiveis_em' => 'datetime',
        ];
    }
}
