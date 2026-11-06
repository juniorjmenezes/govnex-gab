<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Hub\HubCallbackSignatureValidator;
use App\Services\Hub\HubEventProcessor;
use App\Services\Hub\HubWebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Recebe os avisos de pessoa, vínculo e estrutura (entidade/unidade) do
 * Govnex Hub.
 *
 * Mesma forma do receptor de callbacks do WhatsApp: assinatura primeiro,
 * formato depois, idempotência (caixa de entrada `hub_webhook_eventos`) antes
 * de aplicar qualquer coisa; nada é gravado antes da assinatura e do formato.
 * A ordem importa — validar o corpo antes da assinatura daria a um
 * desconhecido uma maneira de descobrir o que o endpoint aceita.
 */
class HubCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        HubCallbackSignatureValidator $signature,
        HubWebhookInbox $inbox,
    ): JsonResponse {
        try {
            $signature->validate($request);
        } catch (RuntimeException) {
            return response()->json(['ok' => false, 'message' => 'Webhook recusado.'], 401);
        }

        $validator = Validator::make((array) $request->json()->all(), [
            'id' => ['required', 'uuid'],
            'tipo' => ['required', 'string', 'in:'.implode(',', HubEventProcessor::TIPOS)],
            'sistema' => ['required', 'string', 'in:'.config('services.hub.codigo', 'GEP')],
            'ocorrido_em' => ['nullable', 'date'],
            'dados' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => 'Webhook inválido.'], 422);
        }

        // Idempotência e estados na caixa de entrada durável
        // (`hub_webhook_eventos`); os códigos de resposta são os do contrato
        // com o Hub — ver `HubWebhookInbox`.
        [$status, $body] = $inbox->receive($validator->validated());

        return response()->json($body, $status);
    }
}
