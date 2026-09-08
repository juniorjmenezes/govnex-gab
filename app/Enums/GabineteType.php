<?php

namespace App\Enums;

enum GabineteType: string
{
    case IndependentOffice = 'GABINETE_INDEPENDENTE';
    case CouncilorOffice = 'GABINETE_VEREADOR';
    case MayorOffice = 'GABINETE_PREFEITO';
    case Secretariat = 'SECRETARIA';
    case AdministrativeDepartment = 'SETOR_ADMINISTRATIVO';

    public function label(): string
    {
        return match ($this) {
            self::IndependentOffice => 'Gabinete independente',
            self::CouncilorOffice => 'Gabinete parlamentar',
            self::MayorOffice => 'Gabinete do prefeito',
            self::Secretariat => 'Secretaria',
            self::AdministrativeDepartment => 'Setor administrativo',
        };
    }

    public function leaderLabel(): string
    {
        return match ($this) {
            self::CouncilorOffice => 'Vereador',
            self::MayorOffice => 'Prefeito',
            self::Secretariat => 'Secretário',
            self::IndependentOffice, self::AdministrativeDepartment => 'Responsável',
        };
    }
}
