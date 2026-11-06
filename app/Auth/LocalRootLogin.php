<?php

namespace App\Auth;

use App\Models\AccessContextEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Acesso local de contingência do root (PLATFORM.md §4).
 *
 * O login local (senha + 2FA, ou passkey) é só do root e só funciona quando
 * `govnexgep.root_login_enabled` está ligado — ou quando o Hub nem está
 * configurado (ambiente novo, sem `HUB_*`, que ainda não tem outra porta de
 * entrada). Em produção com o Hub, fica desligado e só é ligado em
 * emergência.
 *
 * Toda tentativa contra uma conta root é auditada como evento de contingência
 * (`ROOT_LOGIN_LOCAL` em `contexto_acesso_eventos` + log `warning`). A senha
 * nunca é registrada.
 */
class LocalRootLogin
{
    public const EVENT = 'ROOT_LOGIN_LOCAL';

    public static function hubConfigured(): bool
    {
        return filled(config('services.hub.base_url'))
            && filled(config('services.hub.client_id'))
            && filled(config('services.hub.client_secret'));
    }

    public static function enabled(): bool
    {
        return (bool) config('govnexgep.root_login_enabled') || ! self::hubConfigured();
    }

    /**
     * @param  'PERMITIDO'|'NEGADO'  $result
     * @param  array<string, mixed>  $context
     */
    public static function audit(Request $request, ?User $user, string $result, string $reason, array $context = []): void
    {
        $key = (string) config('app.key');
        $payload = [
            'contingencia' => true,
            'motivo' => $reason,
            'flag_ligada' => (bool) config('govnexgep.root_login_enabled'),
            'hub_configurado' => self::hubConfigured(),
            ...$context,
        ];

        AccessContextEvent::query()->create([
            'entidade_id' => null,
            'gabinete_id' => null,
            'usuario_id' => $user?->id,
            'evento' => self::EVENT,
            'resultado' => $result,
            'rota' => $request->route()?->getName(),
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), $key) : null,
            'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', $request->userAgent(), $key) : null,
            'administrador_plataforma' => true,
            'contexto' => $payload,
            'ocorrido_em' => now(),
        ]);

        Log::warning('Acesso local de contingência do root.', [
            'evento' => self::EVENT,
            'resultado' => $result,
            'usuario_id' => $user?->id,
            'rota' => $request->route()?->getName(),
            ...$payload,
        ]);
    }
}
