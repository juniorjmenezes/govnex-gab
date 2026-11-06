<?php

namespace App\Http\Controllers;

use App\Authorization\OfficeAccess;
use App\Enums\OfficePosition;
use App\Http\Requests\Team\UpdateMemberPositionRequest;
use App\Models\OfficeMember;
use App\Models\User;
use App\Services\Offices\OfficePositionAssigner;
use App\Tenancy\OfficeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        $gabineteId = (int) app(OfficeContext::class)->officeIdFor($request->user());
        $office = app(OfficeContext::class)->officeFor($request->user());

        $order = array_flip(array_map(fn (OfficePosition $p): string => $p->value, OfficePosition::cases()));

        return Inertia::render('team/index', [
            'canAssignPositions' => app(OfficeAccess::class)->canAssignPositions($request->user()),
            // Vereador só pela equipe GOVNEX (root): fora do seletor dos demais.
            'positionOptions' => array_values(array_map(fn (OfficePosition $position): array => [
                'value' => $position->value,
                'label' => $position->label(),
            ], array_filter(
                $office !== null ? OfficePosition::allowedFor($office->tipo_gabinete) : [],
                fn (OfficePosition $position): bool => $request->user()->isRoot() || $position !== OfficePosition::Councilor,
            ))),
            'positionLabels' => collect(OfficePosition::cases())
                ->mapWithKeys(fn (OfficePosition $position): array => [$position->value => $position->label()])
                ->all(),
            'currentUserId' => $request->user()->id,
            'isRoot' => $request->user()->isRoot(),
            // Sem papel do Hub: a lista mostra só o cargo local, com quem
            // ainda não tem cargo por último.
            'members' => OfficeMember::query()
                ->with('usuario:id,name,email,role,is_active,last_login_at,created_at')
                ->where('gabinete_id', $gabineteId)
                ->get()
                ->filter(fn (OfficeMember $membership): bool => ! $membership->usuario->isRoot())
                ->sortBy([
                    fn (OfficeMember $a, OfficeMember $b): int => ($order[$a->cargo?->value] ?? PHP_INT_MAX) <=> ($order[$b->cargo?->value] ?? PHP_INT_MAX),
                    fn (OfficeMember $a, OfficeMember $b): int => strcasecmp($a->usuario->name, $b->usuario->name),
                ])
                ->map(fn (OfficeMember $membership): array => [
                    'id' => $membership->usuario_id,
                    'name' => $membership->usuario->name,
                    'email' => $membership->usuario->email,
                    'position' => $membership->cargo?->value,
                    'is_active' => $membership->ativo,
                    'account_active' => $membership->usuario->is_active,
                    'last_login_at' => $membership->usuario->last_login_at?->toIso8601String(),
                    'created_at' => $membership->ingressou_em?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    /**
     * Pessoas e vínculos são geridos no Govnex Hub; criar, editar, redefinir
     * senha ou remover membro do gabinete localmente foi desligado (ver
     * docs/INTEGRACAO_GOVNEX_HUB.md).
     */
    public function store(Request $request): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }

    /**
     * Cargo é local do GEP (o Hub não o conhece nem o sobrescreve): é a única
     * alteração de vínculo que a Equipe ainda faz. A mudança fica registrada
     * na trilha de eventos de acesso (`contexto_acesso_eventos`).
     */
    public function updatePosition(
        UpdateMemberPositionRequest $request,
        User $usuario,
        OfficePositionAssigner $positions,
    ): RedirectResponse {
        $office = app(OfficeContext::class)->requireOfficeFor($request->user());
        $membership = $positions->membershipOf($office, $usuario);

        abort_if($membership === null, 404);

        $position = OfficePosition::tryFrom((string) $request->validated('cargo'));
        $positions->assign($membership, $position, OfficePositionAssigner::EVENT_TEAM, $request->user(), request: $request);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $position !== null
                ? "Cargo de {$usuario->name} definido como {$position->label()}."
                : "Cargo de {$usuario->name} removido.",
        ]);

        return back();
    }

    public function resetPassword(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }
}
