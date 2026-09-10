import { usePage } from '@inertiajs/react';
import {
    GraphUpIcon,
    MapPointIcon,
    SettingsIcon,
    ShieldUserIcon,
    TagHorizontalIcon,
} from '@/components/icons';
import { contextualUrl } from '@/lib/entity-context';
import { hasModule } from '@/lib/modules';
import type { Auth, GabineteModuleCode, NavItem } from '@/types';

type ModuleNavItem = NavItem & { module?: GabineteModuleCode };

/**
 * Itens do menubar de gestão. O `module` de cada item espelha o middleware
 * `module:` que protege a rota correspondente em `routes/tenant.php` — sem
 * isso o menubar exibiria links que só levam à tela de módulo indisponível.
 * Equipe e Configurações pertencem ao núcleo e não dependem de módulo.
 */
export function useManagementNavItems(): NavItem[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const isRoot = auth.user.role === 'root';
    const canManageTeam = ['vereador', 'chefe_gabinete'].includes(
        auth.user.role,
    );

    if (isRoot) {
        return [];
    }

    const items: ModuleNavItem[] = [
        {
            title: 'Categorias',
            href: '/categorias',
            icon: TagHorizontalIcon,
            module: 'DEMANDAS',
        },
        {
            title: 'Bairros',
            href: '/bairros',
            icon: MapPointIcon,
            module: 'RELACIONAMENTO',
        },
        ...(canManageTeam
            ? [{ title: 'Equipe', href: '/equipe', icon: ShieldUserIcon }]
            : []),
        {
            title: 'Relatórios',
            href: '/relatorios',
            icon: GraphUpIcon,
            module: 'RELATORIOS',
        },
        {
            title: 'Configurações',
            href: '/configuracoes/gabinete',
            icon: SettingsIcon,
        },
    ];

    return items
        .filter((item) => !item.module || hasModule(auth.modules, item.module))
        .map((item) => ({
            ...item,
            href: contextualUrl(auth, String(item.href)),
        }));
}
