<?php

namespace App\Enums;

enum EntityInvitationStatus: string
{
    case Pending = 'PENDENTE';
    case Accepted = 'ACEITO';
    case Expired = 'EXPIRADO';
    case Revoked = 'REVOGADO';
}
