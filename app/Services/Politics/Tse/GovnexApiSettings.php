<?php

namespace App\Services\Politics\Tse;

use App\Models\IntegracaoGovnexApi;
use Throwable;

/**
 * Resolve a URL e a chave da GOVNEX API.
 *
 * A configuração passou a ser editável em Administração → Integração GOVNEX
 * API e vive no banco. `config('services.govnex_api.*')` (vindo do `.env`)
 * permanece como padrão: é o que vale numa instalação nova, antes de alguém
 * abrir a tela, e o que mantém ambientes de teste e CI funcionando sem
 * depender de uma linha no banco.
 */
class GovnexApiSettings
{
    private ?IntegracaoGovnexApi $cached = null;

    private bool $loaded = false;

    public function url(): string
    {
        $url = $this->record()?->url;

        return rtrim(
            filled($url) ? (string) $url : (string) config('services.govnex_api.url'),
            '/',
        );
    }

    public function key(): ?string
    {
        $key = $this->record()?->chave;

        if (filled($key)) {
            return (string) $key;
        }

        $fallback = config('services.govnex_api.key');

        return filled($fallback) ? (string) $fallback : null;
    }

    public function record(): ?IntegracaoGovnexApi
    {
        if ($this->loaded) {
            return $this->cached;
        }

        $this->loaded = true;

        try {
            $this->cached = IntegracaoGovnexApi::query()->latest('id')->first();
        } catch (Throwable) {
            // A tabela pode não existir ainda — durante `migrate` ou num
            // ambiente que roda antes desta migration. Cair no `.env` é
            // preferível a derrubar o comando.
            $this->cached = null;
        }

        return $this->cached;
    }

    /** Descarta o cache de requisição após uma gravação na tela. */
    public function forget(): void
    {
        $this->cached = null;
        $this->loaded = false;
    }
}
