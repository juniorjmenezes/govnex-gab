<?php

namespace App\Enums;

/**
 * Estados da caixa de entrada de webhooks do Hub (`hub_webhook_eventos`).
 *
 * PROCESSANDO → PROCESSADO (inclui os avisos ignorados com 200, como
 *                           `entidade_ignorada`: o motivo fica em `resultado`)
 *             ↘ FALHA_TRANSITORIA (falha nossa, 500; retentável até o teto)
 *             ↘ FALHA_PERMANENTE  (payload inaplicável, 409, ou teto de
 *                                  tentativas; só reprocesso explícito)
 *
 * RECEBIDO existe para linhas gravadas fora do fluxo normal (o receptor já
 * grava em PROCESSANDO); o reprocesso as trata como pendentes.
 */
enum HubWebhookStatus: string
{
    case Received = 'RECEBIDO';
    case Processing = 'PROCESSANDO';
    case Processed = 'PROCESSADO';
    case TransientFailure = 'FALHA_TRANSITORIA';
    case PermanentFailure = 'FALHA_PERMANENTE';
}
