<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AccessContextEvent;
use App\Models\Entity;
use App\Models\Office;
use App\Models\User;
use App\Tenancy\EntityContext;
use App\Tenancy\OfficeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveEntityContext
{
    /**
     * Ações pessoais que o auditor pode executar apesar de não serem GET: não
     * alteram dado do gabinete, só o estado de leitura da própria pessoa.
     *
     * @var list<string>
     */
    private const AUDITOR_PERSONAL_ROUTES = [
        'context.notifications.read',
        'context.notifications.read-all',
        'context.knowledge.progress',
    ];

    public function __construct(
        private readonly EntityContext $entidades,
        private readonly OfficeContext $gabinetes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->entidades->clear();
        $this->gabinetes->clear();

        try {
            return $this->handleInContext($request, $next, $user);
        } finally {
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
            $this->ensureAuditorIsReadOnly($request, $user);
        }

        $this->auditContextChange($request, $user, $entidade, $gabinete);

        if ($gabinete !== null) {
            $request->route()?->forgetParameter('entidade');
            $request->route()?->forgetParameter('gabinete');
        }

        return $next($request);
    }

    private function resolveEntidade(mixed $value): Entity
    {
        if ($value instanceof Entity) {
            return $value;
        }

        return Entity::query()
            ->where(function ($query) use ($value): void {
                $query->where('slug', (string) $value);
                if (ctype_digit((string) $value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->firstOrFail();
    }

    private function resolveUnit(mixed $value, Entity $entidade): ?Office
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Office) {
            abort_unless($value->entidade_id === $entidade->id, 404);

            return $value;
        }

        return Office::withoutGlobalScopes()
            ->where('entidade_id', $entidade->id)
            ->where(function ($query) use ($value): void {
                $query->where('slug', (string) $value);
                if (ctype_digit((string) $value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->firstOrFail();
    }

    /**
     * Somente leitura do auditor, aplicada num ponto só: qualquer método que
     * não seja de leitura é recusado aqui, antes de chegar ao controller. As
     * Policies (`canWrite`) são a segunda camada.
     */
    private function ensureAuditorIsReadOnly(Request $request, User $user): void
    {
        if ($this->gabinetes->roleFor($user) !== UserRole::Auditor
            || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (in_array($request->route()?->getName(), self::AUDITOR_PERSONAL_ROUTES, true)) {
            return;
        }

        abort(403, 'Seu perfil de auditor permite apenas consultar as informações deste gabinete.');
    }

    private function auditContextChange(
        Request $request,
        User $user,
        Entity $entidade,
        ?Office $gabinete,
    ): void {
        $signature = $entidade->id.':'.($gabinete !== null ? $gabinete->id : 'entidade');
        $previous = (string) $request->session()->get('govnexgab.context_signature', '');

        if ($previous === $signature) {
            return;
        }

        $key = (string) config('app.key');
        AccessContextEvent::query()->create([
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
