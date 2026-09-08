<?php

namespace App\Enums;

enum UserRole: string
{
    case Root = 'root';
    case Councilor = 'vereador';
    case ChiefOfStaff = 'chefe_gabinete';
    case Advisor = 'assessor';

    public function label(): string
    {
        return match ($this) {
            self::Root => 'Root',
            self::Councilor => 'Vereador',
            self::ChiefOfStaff => 'Chefe de gabinete',
            self::Advisor => 'Assessor',
        };
    }

    public function isRoot(): bool
    {
        return $this === self::Root;
    }

    public function canManageTeam(): bool
    {
        return in_array($this, [self::Root, self::Councilor, self::ChiefOfStaff], true);
    }
}
