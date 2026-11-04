<?php

return [
    'bootstrap_directory' => env('GOVNEXGAB_BOOTSTRAP_DIR', storage_path('app/bootstrap')),

    /*
    | Acesso local de contingência do root (senha/2FA/passkey). Desligado por
    | padrão: com o Govnex Hub configurado, só funciona com esta flag ligada,
    | e só se liga em emergência (Hub fora do ar). Sem o Hub configurado
    | (ambiente novo), o acesso local continua valendo. Ver
    | App\Auth\LocalRootLogin e docs/INTEGRACAO_GOVNEX_HUB.md.
    */
    'root_login_enabled' => (bool) env('GAB_ROOT_LOGIN_ENABLED', false),
];
