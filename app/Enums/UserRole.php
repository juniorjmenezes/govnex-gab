<?php

namespace App\Enums;

/**
 * `users.role`. Só a marca de root (`isRoot()`) é lida: root é a conta local
 * da plataforma, com poder total. Os demais valores são inertes — o GEP
 * decide pelo cargo local (`OfficePosition`) — e saem na limpeza do schema
 * (docs/INTEGRACAO_GOVNEX_HUB.md).
 */
enum UserRole: string
{
    case Root = 'root';
    case Administrator = 'administrador';
    case Operator = 'operador';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Root => 'Root',
            self::Administrator => 'Administrador',
            self::Operator => 'Operador',
            self::Auditor => 'Auditor',
        };
    }

    public function isRoot(): bool
    {
        return $this === self::Root;
    }

    public function isAdministrator(): bool
    {
        return $this === self::Administrator;
    }

    public function isAuditor(): bool
    {
        return $this === self::Auditor;
    }

    public function canManageTeam(): bool
    {
        return in_array($this, [self::Root, self::Administrator], true);
    }

    /** Pode criar e alterar registros; o auditor só lê. */
    public function canWrite(): bool
    {
        return $this !== self::Auditor;
    }

    public function accessRole(): ?AccessRole
    {
        return match ($this) {
            self::Root => null,
            self::Administrator => AccessRole::Administrator,
            self::Operator => AccessRole::Operator,
            self::Auditor => AccessRole::Auditor,
        };
    }
}
