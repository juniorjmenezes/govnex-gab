<?php

namespace App\Http\Controllers;

use App\Enums\AccessRole;
use App\Models\OfficeMember;
use App\Models\User;
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

        return Inertia::render('team/index', [
            'canManage' => $request->user()->canManageGabinete($gabineteId),
            'members' => OfficeMember::query()
                ->with('usuario:id,name,email,role,is_active,last_login_at,created_at')
                ->where('gabinete_id', $gabineteId)
                ->orderByRaw("CASE papel WHEN 'ADMINISTRADOR' THEN 1 WHEN 'OPERADOR' THEN 2 WHEN 'AUDITOR' THEN 3 ELSE 4 END")
                ->get()
                ->filter(fn (OfficeMember $membership): bool => ! $membership->usuario->isRoot())
                ->map(fn (OfficeMember $membership): array => [
                    'id' => $membership->usuario_id,
                    'name' => $membership->usuario->name,
                    'email' => $membership->usuario->email,
                    'role' => $membership->papel->value,
                    'is_active' => $membership->ativo,
                    'account_active' => $membership->usuario->is_active,
                    'last_login_at' => $membership->usuario->last_login_at?->toIso8601String(),
                    'created_at' => $membership->ingressou_em?->toIso8601String(),
                ])->values()->all(),
            'allowedRoles' => array_map(fn (AccessRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ], AccessRole::cases()),
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

    public function resetPassword(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }
}
