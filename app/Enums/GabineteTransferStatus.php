<?php

namespace App\Enums;

enum GabineteTransferStatus: string
{
    case PendingDestination = 'AGUARDANDO_DESTINO';
    case PendingPlatform = 'AGUARDANDO_PLATAFORMA';
    case Completed = 'CONCLUIDA';
    case Rejected = 'RECUSADA';
    case Cancelled = 'CANCELADA';

    public function label(): string
    {
        return match ($this) {
            self::PendingDestination => 'Aguardando destino',
            self::PendingPlatform => 'Aguardando plataforma',
            self::Completed => 'Concluída',
            self::Rejected => 'Recusada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PendingDestination, self::PendingPlatform], true);
    }
}
