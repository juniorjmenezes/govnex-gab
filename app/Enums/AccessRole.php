<?php

namespace App\Enums;

/**
 * Papel de acesso de um vínculo (entidade ou gabinete) — o vocabulário único
 * do ecossistema Govnex, o mesmo que o Hub envia em `Vinculo.papel`.
 *
 * Root não aparece aqui: é conta local de emergência (`UserRole::Root`), nunca
 * vínculo. Funções de negócio (vereador, chefe de gabinete, líder) não são
 * papel de acesso.
 */
enum AccessRole: string
{
    case Administrator = 'ADMINISTRADOR';
    case Operator = 'OPERADOR';
    case Auditor = 'AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Operator => 'Operador',
            self::Auditor => 'Auditor',
        };
    }

    /** Gerencia a entidade ou o gabinete: equipe, convites, configurações. */
    public function canManage(): bool
    {
        return $this === self::Administrator;
    }

    /** Pode criar e alterar registros; o auditor só lê. */
    public function canWrite(): bool
    {
        return $this !== self::Auditor;
    }

    /**
     * Papel na entidade decorrente de um vínculo com uma unidade dela.
     *
     * Administrador de gabinete independente administra a própria entidade —
     * ela não existe sem ele. Em Câmara ou Prefeitura, a entidade é maior que o
     * gabinete e o administrador dele é só mais um operador dela.
     */
    public function forEntidadeOfUnit(?EntidadeType $tipo): self
    {
        return $this === self::Administrator && $tipo !== EntidadeType::IndependentOffice
            ? self::Operator
            : $this;
    }

    public function userRole(): UserRole
    {
        return match ($this) {
            self::Administrator => UserRole::Administrator,
            self::Operator => UserRole::Operator,
            self::Auditor => UserRole::Auditor,
        };
    }
}
