import type { CSSProperties } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useManagementNavItems } from '@/hooks/use-management-nav-items';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    // O cabeçalho é fixo e muda de altura quando a barra de gestão aparece.
    // A altura viaja numa variável CSS porque três lugares dependem dela: o
    // espaçador que compensa o cabeçalho e as telas de tela cheia — os mapas —
    // que descontam isto de 100svh para caber sem rolagem. Os +1px de cada
    // linha somam: sem eles o cabeçalho cobria a borda superior do conteúdo.
    const shellHeight =
        useManagementNavItems().length > 0
            ? 'calc(6rem + 2px)'
            : 'calc(3.5rem + 1px)';

    return (
        <AppShell>
            <AppSidebar />
            <AppContent
                className="overflow-x-hidden"
                style={{ '--app-shell-height': shellHeight } as CSSProperties}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
