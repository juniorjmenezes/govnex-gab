<?php

namespace App\Enums;

enum EntityType: string
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

    /** @return list<OfficeType> */
    public function allowedGabineteTypes(): array
    {
        return match ($this) {
            self::IndependentOffice => [OfficeType::IndependentOffice],
            self::CityCouncil => [
                OfficeType::CouncilorOffice,
                OfficeType::AdministrativeDepartment,
                OfficeType::LegislativeSecretariat,
            ],
            self::CityHall => [OfficeType::MayorOffice, OfficeType::Secretariat, OfficeType::AdministrativeDepartment],
        };
    }

    public function defaultGabineteType(): OfficeType
    {
        return $this->allowedGabineteTypes()[0];
    }

    public function accepts(OfficeType $gabineteType): bool
    {
        return in_array($gabineteType, $this->allowedGabineteTypes(), true);
    }
}
