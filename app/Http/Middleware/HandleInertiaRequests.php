<?php

namespace App\Http\Middleware;

use App\Services\Modules\EntidadeModuleManager;
use App\Services\Modules\GabineteModuleManager;
use App\Tenancy\EntidadeContext;
use App\Tenancy\GabineteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $gabineteContext = app(GabineteContext::class);
        $entidadeContext = app(EntidadeContext::class);
        $entidade = $entidadeContext->entidade();

        if ($user) {
            $gabinete = $gabineteContext->gabinete();

            if ($gabinete === null && $user->gabinete_id !== null) {
                $fallbackGabinete = $user->gabinete()
                    ->select([
                        'id', 'entidade_id', 'tipo_gabinete', 'nome', 'slug', 'status', 'timezone',
                        'bairro', 'logo_path', 'cor_principal',
                    ])
                    ->first();

                // Só assume o gabinete padrão do usuário quando ele pertence à
                // entidade em contexto (ex.: página /entidades/{entidade}). Se o
                // usuário estiver navegando pela entidade de outra pessoa (root),
                // manter o gabinete vazio evita montar um link cruzado inválido.
                if ($entidade === null || $fallbackGabinete?->entidade_id === $entidade->id) {
                    $gabinete = $fallbackGabinete;
                }
            }

            if ($gabinete) {
                $gabinete->setAttribute(
                    'logo_url',
                    $gabinete->logo_path ? Storage::disk('public')->url($gabinete->logo_path) : null,
                );
            }

            $user->setRelation('gabinete', $gabinete);
            $entidade ??= $gabinete?->entidade;
        }

        $gabineteBaseUrl = $entidade && isset($gabinete)
            ? sprintf('/entidades/%s/gabinetes/%s', $entidade->slug, $gabinete->slug)
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'modules' => isset($gabinete)
                    ? app(GabineteModuleManager::class)->activeFor($gabinete)
                    : [],
                'entidade_modules' => $entidade
                    ? app(EntidadeModuleManager::class)->activeFor($entidade)
                    : [],
                'context' => [
                    'entidade' => $entidade ? [
                        'id' => $entidade->id,
                        'name' => $entidade->nome,
                        'slug' => $entidade->slug,
                        'type' => $entidade->tipo->value,
                        'simplified' => $entidade->interface_simplificada,
                        'logo_url' => $entidade->logo_path
                            ? Storage::disk('public')->url($entidade->logo_path)
                            : null,
                        'primary_color' => $entidade->cor_principal,
                        'secondary_color' => $entidade->cor_secundaria,
                    ] : null,
                    'gabinete' => isset($gabinete) ? [
                        'id' => $gabinete->id,
                        'name' => $gabinete->nome,
                        'slug' => $gabinete->slug,
                        'type' => $gabinete->tipo_gabinete->value,
                        'leader_label' => $gabinete->tipo_gabinete->leaderLabel(),
                        'primary_color' => $gabinete->cor_principal,
                    ] : null,
                    'entidade_base_url' => $entidade
                        ? '/entidades/'.$entidade->slug
                        : null,
                    'gabinete_base_url' => $gabineteBaseUrl,
                ],
            ],
            'notifications' => $user ? [
                'unread_count' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(8)->get()->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notificação',
                    'message' => $notification->data['message'] ?? '',
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
            ] : ['unread_count' => 0, 'items' => []],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Entidades e gabinetes nascem no Govnex Hub; a administração do
            // GAB aponta para lá no lugar dos antigos botões de criação.
            'hubStructureUrl' => $user?->isRoot() && config('services.hub.base_url') !== ''
                ? config('services.hub.base_url').'/estrutura'
                : null,
            // Pessoas e vínculos também são geridos no Govnex Hub; telas de
            // equipe/convite ficaram somente leitura e apontam para lá.
            'hubBaseUrl' => config('services.hub.base_url') !== ''
                ? config('services.hub.base_url')
                : null,
        ];
    }
}
