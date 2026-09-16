<?php

namespace App\Http\Middleware;

use App\Models\ContextoAcessoEvento;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyTenantRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $gabinete = $this->resolveFromReferer($request, $user)
            ?? $this->resolveLegacyUnit($user);

        if ($gabinete === null) {
            // Conta ligada a um gabinete que não aceita mais esta pessoa e sem
            // outro vínculo ativo: o diretório de entidades mostra o que ainda
            // está disponível, em vez de um 403 logo após o login.
            if ($user->gabinete_id !== null && ! $user->isRoot()) {
                return redirect()->route('entidades.index')->withErrors([
                    'gabinete' => 'Você não possui mais acesso ao gabinete de origem. Selecione outro contexto.',
                ]);
            }

            return $next($request);
        }

        $entidade = $gabinete->entidade;

        abort_unless(
            $entidade !== null
                && $user->canAccessEntidade($entidade->id)
                && $user->canAccessGabinete($gabinete->id),
            403,
            'O contexto legado não corresponde a um vínculo ativo.',
        );

        $target = sprintf(
            '/entidades/%s/gabinetes/%s/%s',
            $entidade->slug,
            $gabinete->slug,
            ltrim($request->path(), '/'),
        );

        if ($request->getQueryString()) {
            $target .= '?'.$request->getQueryString();
        }

        $this->audit($request, $user, $gabinete, $target);

        return new RedirectResponse(
            $target,
            $request->isMethodSafe() ? 302 : 307,
            [
                'Cache-Control' => 'no-store',
                'X-GovnexGab-Legacy-Redirect' => '1',
            ],
        );
    }

    private function resolveFromReferer(Request $request, User $user): ?Gabinete
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (! is_array($parts)
            || ! isset($parts['host'], $parts['path'])
            || ! hash_equals(mb_strtolower($request->getHost()), mb_strtolower((string) $parts['host']))) {
            return null;
        }

        if (! preg_match(
            '#^/entidades/([^/]+)/gabinetes/([^/]+)(?:/|$)#',
            (string) $parts['path'],
            $matches,
        )) {
            return null;
        }

        $entidadeKey = rawurldecode($matches[1]);
        $gabineteKey = rawurldecode($matches[2]);
        $gabinete = Gabinete::withoutGlobalScopes()
            ->with('entidade')
            ->where(function ($query) use ($gabineteKey): void {
                $query->where('slug', $gabineteKey);
                if (ctype_digit($gabineteKey)) {
                    $query->orWhere('id', (int) $gabineteKey);
                }
            })
            ->whereHas('entidade', function ($query) use ($entidadeKey): void {
                $query->where('slug', $entidadeKey);
                if (ctype_digit($entidadeKey)) {
                    $query->orWhere('id', (int) $entidadeKey);
                }
            })
            ->first();

        if ($gabinete === null
            || ! $user->canAccessEntidade($gabinete->entidade_id)
            || ! $user->canAccessGabinete($gabinete->id)) {
            return null;
        }

        return $gabinete;
    }

    /**
     * users.gabinete_id é gravado na criação da conta e não acompanha os
     * vínculos. Vale enquanto a pessoa ainda tiver acesso a esse gabinete;
     * senão, entra no vínculo ativo mais antigo.
     */
    private function resolveLegacyUnit(User $user): ?Gabinete
    {
        if ($user->gabinete_id === null) {
            return null;
        }

        $origin = Gabinete::withoutGlobalScopes()
            ->with('entidade')
            ->find($user->gabinete_id);

        if ($user->isRoot() || ($origin !== null && $this->isAvailableTo($origin, $user))) {
            return $origin;
        }

        return GabineteMembro::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->when($origin !== null, fn ($query) => $query->where('gabinete_id', '!=', $origin->id))
            ->orderBy('ingressou_em')
            ->orderBy('id')
            ->pluck('gabinete_id')
            ->map(fn (int $id): ?Gabinete => Gabinete::withoutGlobalScopes()->with('entidade')->find($id))
            ->first(fn (?Gabinete $gabinete): bool => $gabinete !== null && $this->isAvailableTo($gabinete, $user));
    }

    private function isAvailableTo(Gabinete $gabinete, User $user): bool
    {
        return $gabinete->isActive()
            && $gabinete->entidade?->isActive() === true
            && $user->canAccessEntidade($gabinete->entidade_id)
            && $user->canAccessGabinete($gabinete->id);
    }

    private function audit(Request $request, User $user, Gabinete $gabinete, string $target): void
    {
        $key = (string) config('app.key');

        ContextoAcessoEvento::query()->create([
            'entidade_id' => $gabinete->entidade_id,
            'gabinete_id' => $gabinete->id,
            'usuario_id' => $user->id,
            'evento' => 'ROTA_LEGADA_REDIRECIONADA',
            'resultado' => $request->isMethodSafe() ? 'LEITURA' : 'ESCRITA',
            'rota' => $request->route()?->getName(),
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), $key) : null,
            'user_agent_hash' => $request->userAgent()
                ? hash_hmac('sha256', $request->userAgent(), $key)
                : null,
            'administrador_plataforma' => $user->isRoot(),
            'contexto' => [
                'metodo' => $request->method(),
                'caminho' => '/'.$request->path(),
                'destino' => $target,
                'status' => $request->isMethodSafe() ? 302 : 307,
            ],
            'ocorrido_em' => now(),
        ]);
    }
}
