<?php

use App\Http\Controllers\Api\WhatsAppCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/whatsapp/callback', WhatsAppCallbackController::class)
    ->middleware('throttle:120,1');
