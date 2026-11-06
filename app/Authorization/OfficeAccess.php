<?php

namespace App\Authorization;

use App\Enums\OfficePosition;
use App\Models\User;
use App\Tenancy\OfficeContext;

/**
 * Único lugar que decide o que uma pessoa pode fazer no gabinete em que atua.
 *
 * A decisão é só pelo cargo local (`gabinete_membros.cargo`,
 * `OfficePosition`). O Hub diz quem é a pessoa e que ela está vinculada; o
 * papel que ele ainda envia é ignorado. Falha fechada: vínculo sem cargo não
 * tem capacidade nenhuma (e nem entra no gabinete — `User::canAccessGabinete()`).
 * Cargo `AUDITOR` só lê.
 *
 * Root (conta local da plataforma) tem poder total em qualquer gabinete.
 *
 * Isolamento entre gabinetes continua nas Policies (`officeIdFor()` contra o
 * `gabinete_id` do registro); aqui só se decide a capacidade.
 */
class OfficeAccess
{
    public function __construct(private readonly OfficeContext $context) {}

    public function can(User $user, OfficeCapability $capability): bool
    {
        if ($user->isRoot()) {
            return true;
        }

        $position = $this->context->positionFor($user);

        return $position !== null && self::positionAllows($position, $capability);
    }

    /**
     * Quem atribui cargos na Equipe: root ou quem tem cargo de vereador ou
     * chefe de gabinete no gabinete em que atua. O primeiro cargo de um
     * gabinete é concedido pelo operador do sistema (`gab:cargo-conceder`).
     */
    public function canAssignPositions(User $user): bool
    {
        if ($user->isRoot()) {
            return true;
        }

        return $this->context->officeIdFor($user) !== null
            && $this->context->positionFor($user)?->leadsOffice() === true;
    }

    /**
     * Administração de cargos de qualquer gabinete pela Administração →
     * Gabinetes (partida em tela, sem terminal e sem depender de já haver
     * vereador/chefe no gabinete): só root.
     */
    public function canManagePositionsOnPlatform(User $user): bool
    {
        return $user->isRoot();
    }

    /**
     * Mapa de capacidades para a interface (esconder controles). O servidor
     * continua a autoridade.
     *
     * @return array<string, bool>
     */
    public function capabilitiesFor(User $user): array
    {
        $capabilities = [];

        foreach (OfficeCapability::cases() as $capability) {
            $capabilities[$capability->value] = $this->can($user, $capability);
        }

        $capabilities['assignPositions'] = $this->canAssignPositions($user);

        return $capabilities;
    }

    /**
     * Matriz por cargo.
     *
     * - Vereador e chefe de gabinete: os mesmos poderes, todos.
     * - Assessor: cria e altera (inclusive no módulo da Secretaria
     *   legislativa); não exclui, não redistribui demandas, não exporta
     *   relatórios, não configura o gabinete.
     * - Secretário: atua só no módulo Secretaria legislativa. Poderes fora
     *   dele ainda estão indefinidos — PENDENTE: por ora só lê o resto.
     * - Auditor: somente leitura.
     */
    private static function positionAllows(OfficePosition $position, OfficeCapability $capability): bool
    {
        return match ($position) {
            OfficePosition::Councilor, OfficePosition::ChiefOfStaff => true,
            OfficePosition::Auditor => false,
            OfficePosition::Advisor => in_array($capability, [
                OfficeCapability::WriteRecords,
                OfficeCapability::WriteLegislativeSecretariat,
            ], true),
            OfficePosition::Secretary => in_array($capability, [
                OfficeCapability::WriteLegislativeSecretariat,
                OfficeCapability::DeleteLegislativeSecretariat,
                OfficeCapability::ModerateLegislativeSecretariat,
            ], true),
        };
    }
}
