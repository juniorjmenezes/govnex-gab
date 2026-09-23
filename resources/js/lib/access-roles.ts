/**
 * Papel de acesso de um vínculo (entidade ou gabinete) — espelha
 * `App\Enums\AccessRole`. Root é conta local e não é vínculo.
 */
export type AccessRole = 'ADMINISTRADOR' | 'OPERADOR' | 'AUDITOR';

export const accessRoleLabels: Record<AccessRole, string> = {
    ADMINISTRADOR: 'Administrador',
    OPERADOR: 'Operador',
    AUDITOR: 'Auditor',
};

export function accessRoleLabel(role: string): string {
    return accessRoleLabels[role as AccessRole] ?? role;
}
