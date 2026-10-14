<?php

namespace App\Enums;

/**
 * Cargo do gabinete de vereador na comissão. Só há um presidente por comissão;
 * os demais cargos (inclusive suplente) não têm limite. A ordem dos casos é a
 * ordem de exibição na lista de membros.
 */
enum CommitteeRole: string
{
    case President = 'presidente';
    case Rapporteur = 'relator';
    case Member = 'membro';
    case Substitute = 'suplente';

    public function label(): string
    {
        return match ($this) {
            self::President => 'Presidente',
            self::Rapporteur => 'Relator',
            self::Member => 'Membro',
            self::Substitute => 'Suplente',
        };
    }
}
