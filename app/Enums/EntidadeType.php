<?php

namespace App\Enums;

enum EntidadeType: string
{
    case IndependentOffice = 'GABINETE_INDEPENDENTE';
    case CityCouncil = 'CAMARA_MUNICIPAL';
    case CityHall = 'PREFEITURA';

    public function label(): string
    {
        return match ($this) {
            self::IndependentOffice => 'Gabinete independente',
            self::CityCouncil => 'Câmara Municipal',
            self::CityHall => 'Prefeitura',
        };
    }

    /** @return list<GabineteType> */
    public function allowedGabineteTypes(): array
    {
        return match ($this) {
            self::IndependentOffice => [GabineteType::IndependentOffice],
            self::CityCouncil => [GabineteType::CouncilorOffice, GabineteType::AdministrativeDepartment],
            self::CityHall => [GabineteType::MayorOffice, GabineteType::Secretariat, GabineteType::AdministrativeDepartment],
        };
    }

    public function defaultGabineteType(): GabineteType
    {
        return $this->allowedGabineteTypes()[0];
    }

    public function accepts(GabineteType $gabineteType): bool
    {
        return in_array($gabineteType, $this->allowedGabineteTypes(), true);
    }
}
