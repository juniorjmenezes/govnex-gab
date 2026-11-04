<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\OfficeAccess;
use App\Enums\OfficePosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOfficeMemberPositionRequest;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use App\Services\Offices\OfficePositionAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipe e cargos de qualquer gabinete, para o root: é a partida da
 * administração de cargos em tela (o `gab:cargo-conceder` fica como
 * contingência). Pessoas e vínculos continuam só no Govnex Hub — aqui só se
 * escolhe o cargo, com motivo auditado.
 */
class OfficePositionController extends Controller
{
    public function index(Request $request, Office $office): Response
    {
        abort_unless(app(OfficeAccess::class)->canManagePositionsOnPlatform($request->user()), 403);

        $order = array_flip(array_map(fn (OfficePosition $p): string => $p->value, OfficePosition::cases()));

        return Inertia::render('admin/offices/positions', [
            'office' => [
                'id' => $office->id,
                'name' => $office->nome,
                'type_label' => $office->tipo_gabinete->label(),
                'entidade' => $office->entidade?->nome,
            ],
            'positionOptions' => array_map(fn (OfficePosition $position): array => [
                'value' => $position->value,
                'label' => $position->label(),
            ], OfficePosition::allowedFor($office->tipo_gabinete)),
            'members' => OfficeMember::query()
                ->with('usuario:id,name,email,role,is_active,last_login_at')
                ->where('gabinete_id', $office->id)
                ->get()
                ->filter(fn (OfficeMember $membership): bool => ! $membership->usuario->isRoot())
                ->sortBy([
                    fn (OfficeMember $a, OfficeMember $b): int => ($b->ativo <=> $a->ativo),
                    fn (OfficeMember $a, OfficeMember $b): int => ($order[$a->cargo?->value] ?? PHP_INT_MAX) <=> ($order[$b->cargo?->value] ?? PHP_INT_MAX),
                    fn (OfficeMember $a, OfficeMember $b): int => strcasecmp($a->usuario->name, $b->usuario->name),
                ])
                ->map(fn (OfficeMember $membership): array => [
                    'id' => $membership->usuario_id,
                    'name' => $membership->usuario->name,
                    'email' => $membership->usuario->email,
                    'position' => $membership->cargo?->value,
                    'link_active' => $membership->ativo,
                    'account_active' => $membership->usuario->is_active,
                    'last_login_at' => $membership->usuario->last_login_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function update(
        UpdateOfficeMemberPositionRequest $request,
        Office $office,
        User $member,
        OfficePositionAssigner $positions,
    ): RedirectResponse {
        $membership = $positions->membershipOf($office, $member);

        abort_if($membership === null, 404);

        $position = OfficePosition::tryFrom((string) $request->validated('cargo'));
        $changed = $positions->assign($membership, $position, OfficePositionAssigner::EVENT_OPERATOR, $request->user(), [
            'origem' => 'tela_administracao',
            'ator' => 'root',
            'motivo' => trim((string) $request->validated('motivo')),
        ], $request, replace: $request->boolean('substituir'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ! $changed
                ? "{$member->name} já estava com esse cargo. Nada alterado."
                : ($position !== null
                    ? "Cargo de {$member->name} definido como {$position->label()}."
                    : "Cargo de {$member->name} removido."),
        ]);

        return back();
    }
}
