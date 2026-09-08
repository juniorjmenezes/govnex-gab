<?php

namespace App\Enums;

enum WhatsAppConsentAction: string
{
    case Accepted = 'ACCEPTED';
    case Revoked = 'REVOKED';
}
