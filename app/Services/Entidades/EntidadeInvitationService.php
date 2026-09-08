<?php

namespace App\Services\Entidades;

use App\Enums\EntidadeInvitationStatus;
use App\Enums\EntidadeRole;
use App\Enums\GabineteRole;
use App\Enums\UserRole;
use App\Models\Entidade;
use App\Models\EntidadeConvite;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use App\Notifications\EntidadeInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EntidadeInvitationService
{
    /** @return list<EntidadeRole> */
    public function grantableEntidadeRoles(User $actor, Entidade $entidade): array
    {
        if ($actor->isRoot() || $actor->entidadeRole($entidade->id) === EntidadeRole::Administrator) {
            return EntidadeRole::cases();
        }

        if ($actor->entidadeRole($entidade->id) === EntidadeRole::Manager) {
            return [EntidadeRole::Manager, EntidadeRole::Operator, EntidadeRole::Auditor];
        }

        return [];
    }

    public function canGrantGabineteAccess(User $actor, Entidade $entidade, Gabinete $gabinete): bool
    {
        return $gabinete->entidade_id === $entidade->id
            && ($actor->isRoot()
                || $actor->entidadeRole($entidade->id) === EntidadeRole::Administrator
                || $actor->canManageGabinete($gabinete->id));
    }

    /** @return list<GabineteRole> */
    public function grantableGabineteRoles(User $actor, Entidade $entidade, Gabinete $gabinete): array
    {
        if (! $this->canGrantGabineteAccess($actor, $entidade, $gabinete)) {
            return [];
        }

        // A liderança possui histórico próprio e deve ser alterada pelo fluxo específico.
        return [GabineteRole::Manager, GabineteRole::Member];
    }

    /**
     * @return array{invitation: EntidadeConvite, credential: string}
     */
    public function invite(
        Entidade $entidade,
        ?Gabinete $gabinete,
        string $email,
        EntidadeRole $entidadeRole,
        ?GabineteRole $gabineteRole,
        User $actor,
        string $deliveryMode = 'EMAIL',
    ): array {
        if (! $actor->canManageEntidade($entidade->id)) {
            abort(403);
        }

        if (! in_array($entidadeRole, $this->grantableEntidadeRoles($actor, $entidade), true)) {
            throw ValidationException::withMessages([
                'entidade_role' => 'Você não pode conceder este papel na entidade.',
            ]);
        }

        if ($gabinete !== null && $gabinete->entidade_id !== $entidade->id) {
            throw ValidationException::withMessages([
                'gabinete' => 'O gabinete selecionado não pertence à entidade.',
            ]);
        }

        if (($gabinete === null) !== ($gabineteRole === null)) {
            throw ValidationException::withMessages([
                'papel_gabinete' => 'O papel do gabinete deve acompanhar um gabinete válido.',
            ]);
        }

        if ($gabinete !== null && ! $this->canGrantGabineteAccess($actor, $entidade, $gabinete)) {
            abort(403, 'Você não pode conceder acesso a este gabinete.');
        }

        if ($gabinete !== null
            && ! in_array($gabineteRole, $this->grantableGabineteRoles($actor, $entidade, $gabinete), true)) {
            throw ValidationException::withMessages([
                'papel_gabinete' => $gabineteRole === GabineteRole::Leader
                    ? 'A liderança deve ser definida pelo fluxo específico do gabinete.'
                    : 'Você não pode conceder este papel no gabinete.',
            ]);
        }

        $deliveryMode = strtoupper($deliveryMode);
        if (! in_array($deliveryMode, ['EMAIL', 'SENHA_TEMPORARIA'], true)) {
            throw ValidationException::withMessages([
                'delivery_mode' => 'O modo de entrega do convite é inválido.',
            ]);
        }

        $email = Str::lower(trim($email));
        $credential = $deliveryMode === 'EMAIL'
            ? Str::random(64)
            : strtoupper(Str::random(5).'-'.Str::random(5).'-'.Str::random(5));

        $invitation = DB::transaction(function () use (
            $entidade,
            $gabinete,
            $email,
            $entidadeRole,
            $gabineteRole,
            $actor,
            $deliveryMode,
            $credential,
        ): EntidadeConvite {
            EntidadeConvite::query()
                ->where('entidade_id', $entidade->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('status', EntidadeInvitationStatus::Pending)
                ->lockForUpdate()
                ->update(['status' => EntidadeInvitationStatus::Revoked]);

            return EntidadeConvite::query()->create([
                'entidade_id' => $entidade->id,
                'gabinete_id' => $gabinete?->id,
                'email' => $email,
                'papel_entidade' => $entidadeRole,
                'papel_gabinete' => $gabineteRole,
                'token_hash' => hash('sha256', $credential),
                'status' => EntidadeInvitationStatus::Pending,
                'modo_entrega' => $deliveryMode,
                'exige_troca_senha' => $deliveryMode === 'SENHA_TEMPORARIA',
                'expira_em' => now()->addHours(48),
                'convidado_por' => $actor->id,
            ]);
        });

        if ($deliveryMode === 'EMAIL') {
            Notification::route('mail', $email)->notify(
                new EntidadeInvitationNotification($invitation, $credential),
            );
        }

        return ['invitation' => $invitation, 'credential' => $credential];
    }

    public function findPending(string $credential): EntidadeConvite
    {
        $invitation = EntidadeConvite::query()
            ->with(['entidade', 'gabinete'])
            ->where('token_hash', hash('sha256', $credential))
            ->firstOrFail();

        if ($invitation->status !== EntidadeInvitationStatus::Pending) {
            throw ValidationException::withMessages(['invitation' => 'Este convite não está mais disponível.']);
        }

        if ($invitation->expira_em->isPast()) {
            $invitation->forceFill(['status' => EntidadeInvitationStatus::Expired])->save();
            throw ValidationException::withMessages(['invitation' => 'Este convite expirou. Solicite um novo convite.']);
        }

        return $invitation;
    }

    public function accept(
        string $credential,
        ?User $authenticatedUser,
        ?string $name = null,
        ?string $password = null,
    ): User {
        $this->findPending($credential);

        return DB::transaction(function () use ($credential, $authenticatedUser, $name, $password): User {
            $invitation = EntidadeConvite::query()
                ->where('token_hash', hash('sha256', $credential))
                ->lockForUpdate()
                ->firstOrFail();

            if ($invitation->status !== EntidadeInvitationStatus::Pending) {
                throw ValidationException::withMessages(['invitation' => 'Este convite não está mais disponível.']);
            }

            $existingUser = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])
                ->first();

            if ($existingUser !== null) {
                if ($existingUser->trashed() || ! $existingUser->is_active) {
                    throw ValidationException::withMessages(['email' => 'A conta associada ao convite está inativa.']);
                }

                if ($authenticatedUser?->id !== $existingUser->id) {
                    throw ValidationException::withMessages([
                        'email' => 'Entre com a conta convidada antes de aceitar este vínculo.',
                    ]);
                }

                $user = $existingUser;
            } else {
                if (trim((string) $name) === '' || strlen((string) $password) < 12) {
                    throw ValidationException::withMessages([
                        'password' => 'Informe seu nome e uma senha com pelo menos 12 caracteres.',
                    ]);
                }

                $legacyRole = match ($invitation->papel_gabinete) {
                    GabineteRole::Leader => UserRole::Councilor,
                    GabineteRole::Manager => UserRole::ChiefOfStaff,
                    default => UserRole::Advisor,
                };

                $user = User::query()->create([
                    'gabinete_id' => $invitation->gabinete_id,
                    'name' => trim((string) $name),
                    'email' => Str::lower($invitation->email),
                    'password' => Hash::make((string) $password),
                    'role' => $legacyRole,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            }

            EntidadeMembro::query()->updateOrCreate(
                ['entidade_id' => $invitation->entidade_id, 'usuario_id' => $user->id],
                [
                    'papel' => $invitation->papel_entidade,
                    'ativo' => true,
                    'ingressou_em' => now(),
                    'desativado_em' => null,
                    'criado_por' => $invitation->convidado_por,
                ],
            );

            if ($invitation->gabinete_id !== null && $invitation->papel_gabinete !== null) {
                GabineteMembro::query()->updateOrCreate(
                    ['gabinete_id' => $invitation->gabinete_id, 'usuario_id' => $user->id],
                    [
                        'papel' => $invitation->papel_gabinete,
                        'ativo' => true,
                        'ingressou_em' => now(),
                        'desativado_em' => null,
                        'criado_por' => $invitation->convidado_por,
                    ],
                );
            }

            $invitation->forceFill([
                'status' => EntidadeInvitationStatus::Accepted,
                'aceito_em' => now(),
                'aceito_por' => $user->id,
            ])->save();

            return $user;
        });
    }
}
