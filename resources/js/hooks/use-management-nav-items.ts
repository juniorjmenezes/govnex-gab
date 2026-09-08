import { usePage } from '@inertiajs/react';
import {
    GraphUpIcon,
    MapPointIcon,
    SettingsIcon,
    ShieldUserIcon,
    TagHorizontalIcon,
} from '@solar-icons/react/outline';
import type { Auth, NavItem } from '@/types';

export function useManagementNavItems(): NavItem[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const isRoot = auth.user.role === 'root';
    const canManageTeam = ['vereador', 'chefe_gabinete'].includes(
        auth.user.role,
    );

    if (isRoot) {
        return [];
    }

    return [
        { title: 'Categorias', href: '/categorias', icon: TagHorizontalIcon },
        { title: 'Bairros', href: '/bairros', icon: MapPointIcon },
        ...(canManageTeam
            ? [{ title: 'Equipe', href: '/equipe', icon: ShieldUserIcon }]
            : []),
        {
            title: 'Relatórios',
            href: '/relatorios',
            icon: GraphUpIcon,
        },
        {
            title: 'Configurações',
            href: '/configuracoes/gabinete',
            icon: SettingsIcon,
        },
    ];
}
