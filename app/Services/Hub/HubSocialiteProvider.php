<?php

namespace App\Services\Hub;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;
use Throwable;

/**
 * Cliente OIDC do GOVNEX Hub sobre o Socialite.
 *
 * Não existe pacote de *provider* OIDC genérico publicado para Socialite
 * (`socialiteproviders/*` só tem provedores concretos), e o que falta do
 * genérico aqui é pequeno: descobrir três URLs e ler três claims. Um provider
 * próprio de ~80 linhas custa menos do que dobrar um provider de outro produto
 * (Keycloak) em cima de endereços que não são os dele.
 *
 * Duas decisões que vale registrar:
 *
 * - **Sem `nonce`.** O Hub não devolve a claim (`docs/INTEGRACAO_GOVNEX_HUB.md`,
 *   passo 2). Mandar `nonce` faria o handshake falhar na validação do lado de
 *   cá sem ganho nenhum. O que protege o fluxo é `state` (CSRF) + PKCE S256,
 *   ambos ligados.
 * - **Perfil vem do `userinfo`, não do `id_token`.** O `id_token` é RS256 e
 *   exigiria buscar e cachear o JWKS só para reler claims que o `userinfo`
 *   entrega pelo access token. O access token chegou pelo canal direto do
 *   `token_endpoint` sobre TLS, então não há intermediário para desconfiar —
 *   é o mesmo motivo pelo qual a própria especificação dispensa validar o
 *   `id_token` obtido diretamente do token endpoint.
 */
class HubSocialiteProvider extends AbstractProvider implements ProviderInterface
{
    /** OIDC usa espaço entre escopos; o padrão do Socialite é vírgula. */
    protected $scopeSeparator = ' ';

    /** @var array<int, string> */
    protected $scopes = ['openid', 'profile', 'email'];

    /** O Hub exige `code_challenge_method=S256` no `/oauth/authorize`. */
    protected $usesPKCE = true;

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoint('authorization_endpoint', '/oauth/authorize'), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoint('token_endpoint', '/oauth/token');
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        $response = Http::acceptJson()
            ->withToken((string) $token)
            ->timeout((int) config('services.hub.timeout', 15))
            ->get($this->endpoint('userinfo_endpoint', '/oauth/userinfo'));

        if (! $response->successful()) {
            throw new RuntimeException('O Govnex Hub recusou a leitura do perfil (HTTP '.$response->status().').');
        }

        return (array) $response->json();
    }

    /** @param  array<string, mixed>  $user */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            // `sub` é o id da pessoa no Hub, sempre string — é o valor que vira
            // `users.hub_user_id` (decisão #3).
            'id' => (string) Arr::get($user, 'sub', ''),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
        ]);
    }

    /**
     * Endereço publicado pelo documento de descoberta, com o caminho conhecido
     * como reserva.
     *
     * O contrato diz que a descoberta é "o único endereço que o GAB precisa
     * configurar", e respeitá-lo é o que permite ao Hub mudar de endereço sem
     * release aqui. Mas uma indisponibilidade momentânea da descoberta não
     * pode derrubar todo o login: os caminhos atuais ficam como reserva.
     */
    private function endpoint(string $chave, string $reserva): string
    {
        $base = $this->baseUrl();
        $valor = Arr::get($this->discovery(), $chave);

        return is_string($valor) && $valor !== '' ? $valor : $base.$reserva;
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        $base = $this->baseUrl();
        $chave = 'hub:oidc:discovery:'.hash('sha256', $base);
        $cacheado = Cache::get($chave);

        if (is_array($cacheado)) {
            return $cacheado;
        }

        // Só o sucesso vai para o cache. Guardar a falha por uma hora
        // transformaria um soluço de rede em uma hora de login pelos caminhos
        // de reserva.
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.hub.timeout', 15))
                ->get($base.'/.well-known/openid-configuration');
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        Cache::put($chave, $documento = (array) $response->json(), now()->addHour());

        return $documento;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.hub.base_url'), '/');

        if ($base === '') {
            throw new RuntimeException('HUB_BASE_URL não está configurada.');
        }

        return $base;
    }
}
