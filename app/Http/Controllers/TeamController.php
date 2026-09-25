<?php

namespace App\Http\Controllers;

use App\Enums\AccessRole;
use App\Http\Requests\Team\ResetTeamMemberPasswordRequest;
use App\Http\Requests\Team\StoreTeamMemberRequest;
use App\Http\Requests\Team\UpdateTeamMemberRequest;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        $gabineteId = (int) $request->user()->gabinete_id;

        return Inertia::render('team/index', [
            'canManage' => $request->user()->canManageGabinete($gabineteId),
            'members' => GabineteMembro::query()
                ->with('usuario:id,name,email,role,is_active,last_login_at,created_at')
                ->where('gabinete_id', $gabineteId)
                ->orderByRaw("CASE papel WHEN 'ADMINISTRADOR' THEN 1 WHEN 'OPERADOR' THEN 2 WHEN 'AUDITOR' THEN 3 ELSE 4 END")
                ->get()
                ->filter(fn (GabineteMembro $membership): bool => ! $membership->usuario->isRoot())
                ->map(fn (GabineteMembro $membership): array => [
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

    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $gabinete = Gabinete::withoutGlobalScopes()->findOrFail($request->user()->gabinete_id);
        $role = AccessRole::from($validated['role']);
        $email = Str::lower($validated['email']);

        DB::transaction(function () use ($request, $validated, $gabinete, $role, $email): void {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user?->isRoot() || ($user !== null && ! $user->is_active)) {
                throw ValidationException::withMessages([
                    'email' => 'Esta conta não pode ser vinculada ao gabinete.',
                ]);
            }

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $validated['name'],
                    'email' => $email,
                    'password' => $validated['password'],
                    'gabinete_id' => $gabinete->id,
                    'role' => $role->userRole(),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ])->save();
            }

            $entidadeMembership = EntidadeMembro::query()->firstOrNew([
                'entidade_id' => $gabinete->entidade_id,
                'usuario_id' => $user->id,
            ]);
            $entidadeMembership->forceFill([
                ...($entidadeMembership->exists ? [] : ['papel' => $role->forEntidadeOfUnit($gabinete->entidade?->tipo)]),
                'ativo' => true,
                'ingressou_em' => $entidadeMembership->ingressou_em ?? now(),
                'desativado_em' => null,
                'criado_por' => $entidadeMembership->criado_por ?? $request->user()->id,
            ])->save();

            GabineteMembro::query()->updateOrCreate(
                ['gabinete_id' => $gabinete->id, 'usuario_id' => $user->id],
                [
                    'papel' => $role,
                    'ativo' => true,
                    'ingressou_em' => now(),
                    'desativado_em' => null,
                    'criado_por' => $request->user()->id,
                ],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membro da equipe cadastrado.']);

        return to_route('team.index');
    }

    public function update(UpdateTeamMemberRequest $request, User $usuario): RedirectResponse
    {
        GabineteMembro::query()
            ->where('gabinete_id', $request->user()->gabinete_id)
            ->where('usuario_id', $usuario->id)
            ->firstOrFail()
            ->forceFill([
                'papel' => AccessRole::from($request->validated('role')),
                'ativo' => $request->validated('is_active'),
                'desativado_em' => $request->validated('is_active') ? null : now(),
            ])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membro da equipe atualizado.']);

        return to_route('team.index');
    }

    public function resetPassword(ResetTeamMemberPasswordRequest $request, User $usuario): RedirectResponse
    {
        $usuario->forceFill(['password' => $request->validated('password')])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Senha redefinida com sucesso.']);

        return to_route('team.index');
    }

    /**
     * Vínculos são geridos no Govnex Hub; a remoção local de membro do
     * gabinete foi desligada (ver docs/INTEGRACAO_GOVNEX_HUB.md).
     */
    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        abort(403, 'Gerenciado no Govnex Hub — altere lá.');
    }
}
