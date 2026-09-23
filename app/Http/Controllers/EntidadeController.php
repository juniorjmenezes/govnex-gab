<?php

namespace App\Http\Controllers;

use App\Enums\AccessRole;
use App\Enums\EntidadeModule;
use App\Enums\EntidadeType;
use App\Models\Entidade;
use App\Models\EntidadeBairro;
use App\Models\EntidadeConvite;
use App\Models\User;
use App\Services\Entidades\EntidadeInvitationService;
use App\Services\Entidades\EntidadeQuotaService;
use App\Services\Modules\EntidadeModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EntidadeController extends Controller
{
    public function show(
        Request $request,
        Entidade $entidade,
        EntidadeModuleManager $modules,
        EntidadeQuotaService $quotas,
        EntidadeInvitationService $invitations,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAccessEntidade($entidade->id), 403);
        $canManage = $user->canManageEntidade($entidade->id);
        $license = $quotas->currentLicense($entidade->id);

        $entidade->load([
            'gabinetes' => fn ($query) => $query->withoutGlobalScopes()->orderBy('nome'),
            'membros.usuario:id,name,email,is_active',
            'convites' => fn ($query) => $query->latest()->limit(30),
            'bairros' => fn ($query) => $query->orderBy('nome'),
        ]);

        return Inertia::render('entities/show', [
            'entidade' => [
                'id' => $entidade->id,
                'name' => $entidade->nome,
                'slug' => $entidade->slug,
                'type' => $entidade->tipo->value,
                'type_label' => $entidade->tipo->label(),
                'status' => $entidade->status->value,
                'city' => $entidade->municipio,
                'state' => $entidade->estado,
                'timezone' => $entidade->timezone,
                'logo_path' => $entidade->logo_path,
                'logo_url' => $entidade->logo_path
                    ? Storage::disk('public')->url($entidade->logo_path)
                    : null,
                'primary_color' => $entidade->cor_principal,
                'secondary_color' => $entidade->cor_secundaria,
                'simplified_interface' => $entidade->interface_simplificada,
            ],
            'gabinetes' => $entidade->gabinetes->map(fn ($gabinete): array => [
                'id' => $gabinete->id,
                'name' => $gabinete->nome,
                'slug' => $gabinete->slug,
                'type' => $gabinete->tipo_gabinete->value,
                'type_label' => $gabinete->tipo_gabinete->label(),
                'active' => $gabinete->isActive(),
                'can_access' => $user->canAccessGabinete($gabinete->id),
                'can_invite_members' => $invitations->canGrantGabineteAccess($user, $entidade, $gabinete),
            ])->values()->all(),
            'members' => $canManage ? $entidade->membros->map(fn ($membership): array => [
                'id' => $membership->id,
                'user_id' => $membership->usuario_id,
                'name' => $membership->usuario?->name,
                'email' => $membership->usuario?->email,
                'role' => $membership->papel->value,
                'active' => $membership->ativo,
            ])->values()->all() : [],
            'invitations' => $canManage ? $entidade->convites->map(fn (EntidadeConvite $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'status' => $invitation->status->value,
                'expires_at' => $invitation->expira_em->toIso8601String(),
            ])->values()->all() : [],
            'sharedNeighborhoods' => $entidade->bairros->map(fn (EntidadeBairro $neighborhood): array => [
                'id' => $neighborhood->id,
                'name' => $neighborhood->nome,
                'city' => $neighborhood->municipio,
                'state' => $neighborhood->estado,
                'active' => $neighborhood->ativo,
                'gabinetes_count' => $neighborhood->bairrosLocais()
                    ->withoutGlobalScopes()
                    ->distinct()
                    ->count('gabinete_id'),
            ])->values()->all(),
            'moduleCatalog' => array_map(fn (EntidadeModule $module): array => [
                'code' => $module->value,
                'name' => $module->label(),
                'scope' => $module->scope()->value,
            ], EntidadeModule::cases()),
            'activeModules' => $modules->activeFor($entidade),
            'license' => $license ? [
                'status' => $license->status->value,
                'starts_at' => $license->inicio_em?->toIso8601String(),
                'ends_at' => $license->fim_em?->toIso8601String(),
                'quotas' => $license->effectiveQuotas(),
            ] : null,
            'canManage' => $canManage,
            'canManageModules' => $user->isRoot(),
            'canCreateGabinete' => $user->isRoot()
                && $entidade->isActive()
                && ($entidade->tipo !== EntidadeType::IndependentOffice
                    || $entidade->gabinetes->isEmpty()),
            'entidadeRoles' => array_map(
                fn (AccessRole $role): string => $role->value,
                $invitations->grantableEntidadeRoles($user, $entidade),
            ),
            'gabineteRoles' => array_map(fn (AccessRole $role): string => $role->value, AccessRole::cases()),
        ]);
    }

    public function update(Request $request, Entidade $entidade): RedirectResponse
    {
        abort_unless($request->user()->canManageEntidade($entidade->id), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'timezone' => ['required', 'timezone'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'simplified_interface' => ['required', 'boolean'],
        ]);

        $this->persistIdentity($entidade, $validated);

        return back()->with('success', 'Identidade da organização atualizada.');
    }

    public function updateIdentity(Request $request, Entidade $entidade): RedirectResponse
    {
        abort_unless($request->user()->canManageEntidade($entidade->id), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'timezone' => ['required', 'timezone'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'simplified_interface' => ['required', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['required', 'boolean'],
        ]);

        $oldLogo = $entidade->logo_path;
        $removeLogo = (bool) $validated['remove_logo'];
        $storedLogo = $request->file('logo')?->store("entidades/{$entidade->id}", 'public');
        $newLogo = is_string($storedLogo) ? $storedLogo : null;

        try {
            DB::transaction(function () use ($entidade, $validated, $newLogo, $removeLogo): void {
                $this->persistIdentity($entidade, $validated, $newLogo, $removeLogo);
            });
        } catch (Throwable $exception) {
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $exception;
        }

        if ($oldLogo && ($newLogo || $removeLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        return back()->with('success', 'Identidade da organização atualizada.');
    }

    public function storeNeighborhood(Request $request, Entidade $entidade): RedirectResponse
    {
        abort_unless($request->user()->canManageEntidade($entidade->id), 403);
        $validated = $this->validateNeighborhood($request, $entidade);

        $entidade->bairros()->create([
            'nome' => $validated['name'],
            'municipio' => $entidade->municipio,
            'estado' => $entidade->estado,
            'ativo' => $validated['active'],
            'criado_por' => $request->user()->id,
        ]);

        return back()->with('success', 'Referência territorial adicionada.');
    }

    public function updateNeighborhood(
        Request $request,
        Entidade $entidade,
        EntidadeBairro $neighborhood,
    ): RedirectResponse {
        abort_unless($request->user()->canManageEntidade($entidade->id), 403);
        abort_unless($neighborhood->entidade_id === $entidade->id, 404);
        $validated = $this->validateNeighborhood($request, $entidade, $neighborhood);

        $neighborhood->forceFill([
            'nome' => $validated['name'],
            'municipio' => $entidade->municipio,
            'estado' => $entidade->estado,
            'ativo' => $validated['active'],
        ])->save();

        return back()->with('success', 'Referência territorial atualizada.');
    }

    public function updateModules(
        Request $request,
        Entidade $entidade,
        EntidadeModuleManager $modules,
    ): RedirectResponse {
        abort_unless($request->user()->isRoot(), 403);
        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['string', Rule::enum(EntidadeModule::class)],
        ]);

        $modules->syncActivation($entidade, $validated['modules'], $request->user());

        return back()->with('success', 'Módulos da organização atualizados.');
    }

    /** @param array<string, mixed> $validated */
    private function persistIdentity(
        Entidade $entidade,
        array $validated,
        ?string $newLogo = null,
        bool $removeLogo = false,
    ): void {
        $entidade->forceFill([
            'nome' => Str::squish($validated['name']),
            'timezone' => $validated['timezone'],
            'cor_principal' => $validated['primary_color'],
            'cor_secundaria' => $validated['secondary_color'],
            'interface_simplificada' => $validated['simplified_interface'],
            ...($newLogo
                ? ['logo_path' => $newLogo]
                : ($removeLogo ? ['logo_path' => null] : [])),
        ])->save();
    }

    /** @return array{name: string, active: bool} */
    private function validateNeighborhood(
        Request $request,
        Entidade $entidade,
        ?EntidadeBairro $neighborhood = null,
    ): array {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('entidade_bairros', 'nome')
                    ->where(fn ($query) => $query
                        ->where('entidade_id', $entidade->id)
                        ->where('municipio', $entidade->municipio)
                        ->where('estado', $entidade->estado))
                    ->ignore($neighborhood?->id),
            ],
            'active' => ['required', 'boolean'],
        ]);

        return [
            'name' => Str::squish($validated['name']),
            'active' => (bool) $validated['active'],
        ];
    }
}
