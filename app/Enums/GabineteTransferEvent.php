<?php

namespace App\Enums;

enum GabineteTransferEvent: string
{
    case RequestedAndAcceptedByOrigin = 'SOLICITADA_E_ACEITA_ORIGEM';
    case AcceptedByDestination = 'ACEITA_DESTINO';
    case ApprovedAndCompleted = 'APROVADA_E_CONCLUIDA';
    case Rejected = 'RECUSADA';
    case Cancelled = 'CANCELADA';

    public function label(): string
    {
        return match ($this) {
            self::RequestedAndAcceptedByOrigin => 'Solicitação registrada e aceita pela origem',
            self::AcceptedByDestination => 'Aceita pela entidade de destino',
            self::ApprovedAndCompleted => 'Aprovada pela plataforma e concluída',
            self::Rejected => 'Recusada',
            self::Cancelled => 'Cancelada pela origem',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Rejected, self::Cancelled => 'negative',
            default => 'positive',
        };
    }
}
