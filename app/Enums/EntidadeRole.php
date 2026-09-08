<?php

namespace App\Enums;

enum EntidadeRole: string
{
    case Administrator = 'ADMINISTRADOR';
    case Manager = 'GESTOR';
    case Operator = 'OPERADOR';
    case Auditor = 'AUDITOR';

    public function canManageEntidade(): bool
    {
        return in_array($this, [self::Administrator, self::Manager], true);
    }
}
