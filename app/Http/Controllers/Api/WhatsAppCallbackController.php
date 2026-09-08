<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppCallbackService;
use App\Services\WhatsApp\WhatsAppCallbackSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class WhatsAppCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsAppCallbackSignatureValidator $signature,
        WhatsAppCallbackService $callbacks,
    ): JsonResponse {
        try {
            $signature->validate($request);
        } catch (RuntimeException) {
            return response()->json(['ok' => false, 'message' => 'Callback recusado.'], 401);
        }
        $validator = Validator::make((array) $request->json()->all(), [
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'in:MESSAGE_STATUS,CONTACT_OPTOUT,INBOUND_MESSAGE'],
            'occurred_at' => ['nullable', 'date'],
            'data' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => 'Callback inválido.'], 422);
        }

        try {
            return response()->json(['ok' => true] + $callbacks->process($validator->validated()));
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Falha temporária ao processar o callback.'], 500);
        }
    }
}
