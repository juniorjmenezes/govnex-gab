<?php

use App\Enums\WhatsAppPurpose;

return [
    'driver' => env('WHATSAPP_DRIVER', 'fake'),
    'real_enabled' => (bool) env('WHATSAPP_REAL_ENABLED', false),
    'gateway_url' => rtrim((string) env('WHATSAPP_GATEWAY_URL', ''), '/'),
    'client_code' => strtoupper((string) env('WHATSAPP_CLIENT_CODE', 'GABINETE')),
    'gateway_account_id' => (int) env('WHATSAPP_GATEWAY_ACCOUNT_ID', 0),
    'request_secret' => env('WHATSAPP_REQUEST_SECRET'),
    'callback_secret' => env('WHATSAPP_CALLBACK_SECRET'),
    'callback_secret_previous' => env('WHATSAPP_CALLBACK_SECRET_PREVIOUS'),
    'callback_previous_valid_until' => env('WHATSAPP_CALLBACK_PREVIOUS_VALID_UNTIL'),
    'phone_hash_secret' => env('WHATSAPP_PHONE_HASH_SECRET'),
    'callback_window_seconds' => 300,
    'retention_days' => 90,
    'consent' => [
        'version' => '1.1',
        'text' => 'Autorizo o GOVNEX GAB, operado pela F3 Sistemas, a enviar por WhatsApp notificações relacionadas ao meu uso ou atendimento na plataforma, incluindo demandas, agenda, pesquisas e outros avisos operacionais. Posso revogar esta autorização a qualquer momento.',
    ],
    'purposes' => array_map(
        static fn (WhatsAppPurpose $purpose): string => $purpose->value,
        WhatsAppPurpose::cases(),
    ),
];
