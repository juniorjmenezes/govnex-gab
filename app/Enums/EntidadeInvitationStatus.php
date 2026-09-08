<?php

namespace App\Enums;

enum EntidadeInvitationStatus: string
{
    case Pending = 'PENDENTE';
    case Accepted = 'ACEITO';
    case Expired = 'EXPIRADO';
    case Revoked = 'REVOGADO';
}
