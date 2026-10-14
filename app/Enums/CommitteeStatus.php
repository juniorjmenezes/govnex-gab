<?php

namespace App\Enums;

/**
 * Situação da comissão. Extinguir preenche `encerrada_em` e encerra a
 * participação dos membros (`saiu_em`); comissão extinta não recebe membros.
 */
enum CommitteeStatus: string
{
    case Active = 'ativa';
    case Extinct = 'extinta';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Extinct => 'Extinta',
        };
    }
}
