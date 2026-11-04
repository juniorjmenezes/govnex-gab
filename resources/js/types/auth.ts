import type { GabineteModuleCode } from './modules';

/**
 * Só a marca de root interessa à interface; o que a pessoa pode fazer vem de
 * `Auth.permissions` (cargo local).
 */
export type UserRole = 'root' | 'membro';

export type EntidadeModuleCode = GabineteModuleCode;

export type GabineteSummary = {
    id: number;
    nome: string;
    slug: string;
    status: 'ativo' | 'suspenso';
    timezone: string;
    bairro: string | null;
    logo_path: string | null;
    logo_url: string | null;
    cor_principal: string | null;
};

export type User = {
    id: number;
    gabinete_id: number | null;
    name: string;
    email: string;
    avatar?: string;
    role: UserRole;
    is_active: boolean;
    gabinete: GabineteSummary | null;
    email_verified_at: string | null;
    last_login_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * Capacidades no gabinete em que a pessoa atua — espelha
 * `App\Authorization\OfficeAccess::capabilitiesFor()`. Só esconde controles.
 */
export type OfficePermissions = {
    writeRecords: boolean;
    deleteRecords: boolean;
    reassignDemands: boolean;
    exportReports: boolean;
    configureOffice: boolean;
    manageTeam: boolean;
    writeLegislativeSecretariat: boolean;
    deleteLegislativeSecretariat: boolean;
    moderateLegislativeSecretariat: boolean;
    assignPositions: boolean;
};

export type Auth = {
    user: User;
    permissions?: OfficePermissions | null;
    modules: GabineteModuleCode[];
    entidade_modules: EntidadeModuleCode[];
    context: {
        entidade: {
            id: number;
            name: string;
            slug: string;
            type: 'GABINETE_INDEPENDENTE' | 'CAMARA_MUNICIPAL' | 'PREFEITURA';
            simplified: boolean;
            logo_url: string | null;
            primary_color: string | null;
            secondary_color: string | null;
        } | null;
        gabinete: {
            id: number;
            name: string;
            slug: string;
            type:
                | 'GABINETE_INDEPENDENTE'
                | 'GABINETE_VEREADOR'
                | 'GABINETE_PREFEITO'
                | 'SECRETARIA'
                | 'SETOR_ADMINISTRATIVO';
            leader_label: string;
            primary_color: string | null;
        } | null;
        entidade_base_url: string | null;
        gabinete_base_url: string | null;
    };
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
