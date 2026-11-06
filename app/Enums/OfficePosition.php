<?php

namespace App\Enums;

/**
 * Cargo local de uma pessoa num gabinete (`gabinete_membros.cargo`).
 *
 * É a autorização do GAB: o Hub só diz quem é a pessoa e que ela está
 * vinculada; o cargo é atribuído e mantido aqui e define o que ela faz no
 * gabinete (ver `App\Authorization\OfficeAccess`). Vínculo sem cargo não
 * acessa o gabinete (falha fechada).
 */
enum OfficePosition: string
{
    case Councilor = 'VEREADOR';
    case ChiefOfStaff = 'CHEFE_GABINETE';
    case Secretary = 'SECRETARIO';
    case Advisor = 'ASSESSOR';
    /** Somente leitura; vale em qualquer tipo de gabinete. */
    case Auditor = 'AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::Councilor => 'Vereador',
            self::ChiefOfStaff => 'Chefe de gabinete',
            self::Secretary => 'Secretário',
            self::Advisor => 'Assessor',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * Cargos que um tipo de gabinete admite — o único lugar que define essa
     * restrição. Auditor (somente leitura) vale em todos. Gabinete
     * independente é o gabinete próprio de um vereador: admite os mesmos
     * cargos do gabinete parlamentar.
     *
     * @return list<self>
     */
    public static function allowedFor(OfficeType $type): array
    {
        return match ($type) {
            OfficeType::CouncilorOffice, OfficeType::IndependentOffice => [self::Councilor, self::ChiefOfStaff, self::Advisor, self::Auditor],
            OfficeType::LegislativeSecretariat => [self::Secretary, self::Advisor, self::Auditor],
            default => [self::Auditor],
        };
    }

    /** Cargos que administram o gabinete (atribuem cargos, configuram, excluem). */
    public function leadsOffice(): bool
    {
        return in_array($this, [self::Councilor, self::ChiefOfStaff], true);
    }

    public function isAllowedFor(OfficeType $type): bool
    {
        return in_array($this, self::allowedFor($type), true);
    }
}
