<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Hub\HubCallbackSignatureValidator;
use App\Services\Hub\HubEventProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Recebe os avisos de pessoa, vínculo e estrutura (entidade/unidade) do
 * Govnex Hub.
 *
 * Mesma forma do receptor de callbacks do WhatsApp: assinatura primeiro,
 * formato depois, idempotência antes de tocar no banco. A ordem importa —
 * validar o corpo antes da assinatura daria a um desconhecido uma maneira de
 * descobrir o que o endpoint aceita.
 */
class HubCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        HubCallbackSignatureValidator $signature,
        HubEventProcessor $processor,
    ): JsonResponse {
        try {
            $signature->validate($request);
        } catch (RuntimeException) {
            return response()->json(['ok' => false, 'message' => 'Webhook recusado.'], 401);
        }

        $validator = Validator::make((array) $request->json()->all(), [
            'id' => ['required', 'uuid'],
            'tipo' => ['required', 'string', 'in:'.implode(',', HubEventProcessor::TIPOS)],
            'sistema' => ['required', 'string', 'in:'.config('services.hub.codigo', 'GAB')],
            'ocorrido_em' => ['nullable', 'date'],
            'dados' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => 'Webhook inválido.'], 422);
        }

        $evento = $validator->validated();

        // O Hub reenfileira o que não confirmou (`hub:reprocessar-webhooks-pendentes`),
        // então a reentrega do mesmo `id` é esperada e precisa responder
        // sucesso — devolver erro só manteria o aviso na caixa de saída dele
        // para sempre.
        if (! Cache::add('hub:webhook:evento:'.$evento['id'], true, now()->addDays(2))) {
            return response()->json(['ok' => true, 'duplicado' => true]);
        }

        try {
            return response()->json(['ok' => true] + $processor->processar($evento));
        } catch (RuntimeException $exception) {
            // Payload coerente com o contrato mas que não dá para aplicar aqui
            // (pessoa duplicada por e-mail, bloco faltando). Repetir daria a
            // mesma resposta, então o evento fica queimado e o problema vai
            // para o log de quem opera.
            Log::warning('Webhook do Hub recusado na aplicação.', [
                'evento' => $evento['id'],
                'tipo' => $evento['tipo'],
                'erro' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            // Falha nossa: libera o `id` para que a reentrega do Hub possa
            // tentar de novo.
            Cache::forget('hub:webhook:evento:'.$evento['id']);
            report($exception);

            return response()->json(['ok' => false, 'message' => 'Falha temporária ao processar o webhook.'], 500);
        }
    }
}
