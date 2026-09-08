<?php

namespace App\Enums;

enum EntidadeQuota: string
{
    case ActiveGabinetes = 'UNIDADES_ATIVAS';
    case ActiveUsers = 'USUARIOS_ATIVOS';
    case StorageBytes = 'ARMAZENAMENTO_BYTES';
    case MonthlyWhatsAppMessages = 'WHATSAPP_MENSAGENS_MES';

    public function blocksWhenExceeded(): bool
    {
        return true;
    }
}
