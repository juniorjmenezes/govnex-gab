<?php

use App\Http\Controllers\Api\HubCallbackController;
use App\Http\Controllers\Api\WhatsAppCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/whatsapp/callback', WhatsAppCallbackController::class)
    ->middleware('throttle:120,1');

// Avisos de pessoa e vínculo do Govnex Hub. Sem `auth`: quem autentica é a
// assinatura HMAC do corpo (`HubCallbackSignatureValidator`), do mesmo jeito
// que no callback do WhatsApp acima.
Route::post('/integrations/hub/webhook', HubCallbackController::class)
    ->middleware('throttle:240,1')
    ->name('api.hub.webhook');
