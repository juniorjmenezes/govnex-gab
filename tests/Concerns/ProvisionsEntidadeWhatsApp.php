<?php

namespace Tests\Concerns;

use App\Enums\EntityWhatsAppConnectionStatus;
use App\Enums\EntityWhatsAppConnectionType;
use App\Enums\WhatsAppPurpose;
use App\Models\EntityWhatsAppConnection;
use App\Models\Office;
use App\Models\WhatsAppTemplatePurpose;
use App\Services\WhatsApp\WhatsAppTemplateCatalog;

trait ProvisionsEntidadeWhatsApp
{
    protected function entidadeWhatsAppConnection(
        Office $office,
        int $gatewayAccountId = 6,
        EntityWhatsAppConnectionType $type = EntityWhatsAppConnectionType::Central,
    ): EntityWhatsAppConnection {
        return EntityWhatsAppConnection::query()->updateOrCreate(
            [
                'entidade_id' => $office->entidade_id,
                'gateway_account_id' => $gatewayAccountId,
            ],
            [
                'tipo' => $type,
                'nome_exibicao' => 'Conta de teste '.$gatewayAccountId,
                'telefone_final' => str_pad((string) $gatewayAccountId, 4, '0', STR_PAD_LEFT),
                'status' => EntityWhatsAppConnectionStatus::Active,
                'ativo' => true,
                'metadados' => ['origem' => 'FIXTURE_TESTE'],
                'atribuido_em' => now(),
                'sincronizado_em' => now(),
                'desativado_em' => null,
            ],
        );
    }

    protected function readyEntidadeWhatsAppTemplate(
        Office $office,
        WhatsAppPurpose $purpose,
        ?int $gatewayTemplateId = null,
    ): WhatsAppTemplatePurpose {
        $connection = $this->entidadeWhatsAppConnection($office);
        $definition = app(WhatsAppTemplateCatalog::class)->definition($purpose);

        return WhatsAppTemplatePurpose::query()->updateOrCreate(
            [
                'entidade_id' => $office->entidade_id,
                'entidade_whatsapp_conexao_id' => $connection->id,
                'finalidade' => $purpose->value,
            ],
            [
                'gateway_template_id' => $gatewayTemplateId
                    ?? 100 + array_search($purpose, WhatsAppPurpose::cases(), true),
                'nome_meta' => $definition['meta_name'],
                'idioma' => 'pt_BR',
                'categoria' => 'UTILITY',
                'status' => 'APPROVED',
                'componentes' => $definition['components'],
                'contrato_hash' => $definition['contract_hash'],
                'ativo' => true,
                'sincronizado_em' => now(),
            ],
        );
    }
}
