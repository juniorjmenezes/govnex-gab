import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import {
    LockKeyholeIcon,
    PaletteIcon,
    UserRoundedIcon,
} from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Surface } from '@/components/ui/surface';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    { title: 'Perfil', href: edit(), icon: UserRoundedIcon },
    { title: 'Segurança', href: editSecurity(), icon: LockKeyholeIcon },
    { title: 'Aparência', href: editAppearance(), icon: PaletteIcon },
];

/**
 * Área de configurações da conta no mesmo padrão das demais páginas:
 * PageContainer + PageHeader, navegação num Surface e cada assunto num card
 * com SurfaceHeader. Em telas pequenas a navegação vira uma faixa horizontal.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <PageContainer>
            <PageHeader
                title="Configurações da conta"
                description="Perfil, segurança e preferências de exibição"
            />

            <div className="grid items-start gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <Surface as="aside" className="overflow-hidden p-2">
                    <nav
                        className="flex gap-1 overflow-x-auto lg:flex-col"
                        aria-label="Configurações da conta"
                    >
                        {sidebarNavItems.map((item) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={toUrl(item.href)}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex h-10 shrink-0 items-center gap-2 rounded-md px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        active &&
                                            'bg-muted font-medium text-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </Surface>

                <div className="flex min-w-0 flex-col gap-6">{children}</div>
            </div>
        </PageContainer>
    );
}
