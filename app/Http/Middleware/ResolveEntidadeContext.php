<?php

namespace App\Http\Middleware;

use App\Enums\GabineteRole;
use App\Enums\UserRole;
use App\Models\ContextoAcessoEvento;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\User;
use App\Tenancy\EntidadeContext;
use App\Tenancy\GabineteContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveEntidadeContext
{
    public function __construct(
        private readonly EntidadeContext $entidades,
        private readonly GabineteContext $gabinetes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->entidades->clear();
        $this->gabinetes->clear();
        $originalGabineteId = $user->getAttribute('gabinete_id');
        $originalRole = $user->getAttribute('role');
        $hadGabineteRelation = $user->relationLoaded('gabinete');
        $originalGabinete = $hadGabineteRelation ? $user->getRelation('gabinete') : null;

        try {
            return $this->handleInContext($request, $next, $user);
        } finally {
            $user->setAttribute('gabinete_id', $originalGabineteId);
            $user->setAttribute('role', $originalRole);

            if ($hadGabineteRelation) {
                $user->setRelation('gabinete', $originalGabinete);
            } else {
                $user->unsetRelation('gabinete');
            }

            $this->entidades->clear();
            $this->gabinetes->clear();
        }
    }

    private function handleInContext(Request $request, Closure $next, User $user): Response
    {

        $entidade = $this->resolveEntidade($request->route('entidade'));
        $gabinete = $this->resolveUnit($request->route('gabinete'), $entidade);

        abort_unless($entidade->isActive(), 403, 'A entidade está temporariamente indisponível.');
        abort_unless($gabinete === null || $gabinete->isActive(), 403, 'O gabinete está temporariamente indisponível.');
        abort_unless($user->canAccessEntidade($entidade->id), 403, 'Você não possui acesso a esta entidade.');
        abort_unless($gabinete === null || $user->canAccessGabinete($gabinete->id), 403, 'Você não possui acesso a este gabinete.');

        $this->entidades->set($entidade);
        $request->route()?->setParameter('entidade', $entidade);

        if ($gabinete !== null) {
            $this->gabinetes->setUnit($gabinete);
            $request->route()?->setParameter('gabinete', $gabinete);
            $this->applyLegacyRequestCompatibility($user, $gabinete);
        }

        $this->auditContextChange($request, $user, $entidade, $gabinete);

        if ($gabinete !== null) {
            $request->route()?->forgetParameter('entidade');
            $request->route()?->forgetParameter('gabinete');
        }

        return $next($request);
    }

    private function resolveEntidade(mixed $value): Entidade
    {
        if ($value instanceof Entidade) {
            return $value;
        }

        return Entidade::query()
            ->where(function ($query) use ($value): void {
                $query->where('slug', (string) $value);
                if (ctype_digit((string) $value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->firstOrFail();
    }

    private function resolveUnit(mixed $value, Entidade $entidade): ?Gabinete
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Gabinete) {
            abort_unless($value->entidade_id === $entidade->id, 404);

            return $value;
        }

        return Gabinete::withoutGlobalScopes()
            ->where('entidade_id', $entidade->id)
            ->where(function ($query) use ($value): void {
                $query->where('slug', (string) $value);
                if (ctype_digit((string) $value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->firstOrFail();
    }

    private function applyLegacyRequestCompatibility(User $user, Gabinete $gabinete): void
    {
        $user->setAttribute('gabinete_id', $gabinete->id);
        $user->setRelation('gabinete', $gabinete);

        if ($user->isRoot()) {
            return;
        }

        $role = $user->gabineteRole($gabinete->id);
        $user->setAttribute('role', match ($role) {
            GabineteRole::Leader => UserRole::Councilor,
            GabineteRole::Manager => UserRole::ChiefOfStaff,
            GabineteRole::Member => UserRole::Advisor,
            default => $user->role,
        });
    }

    private function auditContextChange(
        Request $request,
        User $user,
        Entidade $entidade,
        ?Gabinete $gabinete,
    ): void {
        $signature = $entidade->id.':'.($gabinete !== null ? $gabinete->id : 'entidade');
        $previous = (string) $request->session()->get('govnexgab.context_signature', '');

        if ($previous === $signature) {
            return;
        }

        $key = (string) config('app.key');
        ContextoAcessoEvento::query()->create([
            'entidade_id' => $entidade->id,
            'gabinete_id' => $gabinete?->id,
            'usuario_id' => $user->id,
            'evento' => $previous === '' ? 'CONTEXTO_ENTRADO' : 'CONTEXTO_ALTERADO',
            'resultado' => 'PERMITIDO',
            'rota' => $request->route()?->getName(),
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), $key) : null,
            'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', $request->userAgent(), $key) : null,
            'administrador_plataforma' => $user->isRoot(),
            'contexto' => ['anterior' => $previous !== '' ? $previous : null],
            'ocorrido_em' => now(),
        ]);

        $request->session()->put('govnexgab.context_signature', $signature);
    }
}
