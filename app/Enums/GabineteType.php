<?php

namespace App\Enums;

enum GabineteType: string
{
    case IndependentOffice = 'GABINETE_INDEPENDENTE';
    case CouncilorOffice = 'GABINETE_VEREADOR';
    case MayorOffice = 'GABINETE_PREFEITO';
    case Secretariat = 'SECRETARIA';
    case AdministrativeDepartment = 'SETOR_ADMINISTRATIVO';
    /**
     * Unidade da Câmara que coordena recursos compartilhados entre os
     * gabinetes de vereador (uma por Câmara). O titular é rótulo de função,
     * não papel de acesso: continua `AccessRole::Administrator` do gabinete.
     */
    case LegislativeSecretariat = 'SECRETARIA_LEGISLATIVA';

    public function label(): string
    {
        return match ($this) {
            self::IndependentOffice => 'Gabinete independente',
            self::CouncilorOffice => 'Gabinete parlamentar',
            self::MayorOffice => 'Gabinete do prefeito',
            self::Secretariat => 'Secretaria',
            self::AdministrativeDepartment => 'Setor administrativo',
            self::LegislativeSecretariat => 'Secretaria legislativa',
        };
    }

    public function leaderLabel(): string
    {
        return match ($this) {
            self::CouncilorOffice => 'Vereador',
            self::MayorOffice => 'Prefeito',
            self::Secretariat => 'Secretário',
            self::LegislativeSecretariat => 'Secretário legislativo',
            self::IndependentOffice, self::AdministrativeDepartment => 'Responsável',
        };
    }
}
