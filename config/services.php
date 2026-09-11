<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'fake'),
        'simulated' => env('WHATSAPP_SIMULATED', true),
        'real_enabled' => env('WHATSAPP_REAL_ENABLED', false),
    ],

    'geocoding' => [
        'url' => env('GEOCODING_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEOCODING_USER_AGENT', 'GovnexGab/1.0 ('.env('APP_URL', 'http://localhost').')'),
        'contact_email' => env('GEOCODING_CONTACT_EMAIL'),
        'minimum_interval_ms' => env('GEOCODING_MINIMUM_INTERVAL_MS', 1100),
    ],

    'tse' => [
        // Tempo máximo de cada requisição à GOVNEX API (ver GovnexApiClient).
        'timeout' => env('TSE_TIMEOUT', 600),
        'polling_locations_chunk_size' => env('TSE_POLLING_LOCATIONS_CHUNK_SIZE', 25000),
        'queue_connection' => env('TSE_QUEUE_CONNECTION', 'database'),
        // Aplicado via ini_set() dentro do próprio job (SyncDatasetFromGovnexApi)
        // — não basta configurar -d memory_limit= na invocação de
        // `queue:listen`: ele só ajusta a memória do processo listener, não
        // dos workers `queue:work --once` que ele cria pra processar cada job
        // (Listener::createCommand() não repassa flags -d pro subprocesso).
        // ini_set() dentro do job funciona não importa como o worker foi
        // iniciado. São Paulo sozinho (maior estado) já passa de 700MB pra
        // agregar seus candidatos a vereador em importCandidateVotes().
        'worker_memory_limit' => env('TSE_WORKER_MEMORY_LIMIT', '2048M'),
    ],

    // GOVNEX API — fonte de todos os datasets do TSE (ver GovnexApiClient e
    // GovnexApiDatasetCatalog). Sem GOVNEX_API_KEY, a API ainda responde, só
    // com o rate limit e o per_page mais baixos do consumidor anônimo.
    'govnex_api' => [
        'url' => env('GOVNEX_API_URL', 'http://127.0.0.1:8020/api/v1'),
        'key' => env('GOVNEX_API_KEY'),
    ],

    'pollingdata' => [
        'url' => env('POLLINGDATA_URL', 'https://flex.pollingdata.com.br/api/polls/candidates'),
        'user_agent' => env('POLLINGDATA_USER_AGENT', 'GovnexGab/1.0 ('.env('APP_URL', 'http://localhost').')'),
        'timeout' => env('POLLINGDATA_TIMEOUT', 30),
        // Intervalo entre chamadas ao sincronizar vários contextos em
        // sequência (ex.: as 27 UFs de governador) — nunca concorrente.
        'interval_ms' => env('POLLINGDATA_INTERVAL_MS', 300),
    ],

];
