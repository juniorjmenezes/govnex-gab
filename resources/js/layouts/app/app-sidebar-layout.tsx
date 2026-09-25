import type { CSSProperties } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

// Altura do cabeçalho fixo (h-14) mais a borda inferior. Viaja numa variável
// CSS porque três lugares dependem dela: o espaçador que compensa o cabeçalho
// e as telas de tela cheia — os mapas — que descontam isto de 100svh para
// caber sem rolagem. Os itens de gestão passaram para a sidebar, então a
// altura é única (antes crescia com a faixa de gestão).
const SHELL_HEIGHT = 'calc(3.5rem + 1px)';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell>
            <AppSidebar />
            <AppContent
                className="overflow-x-hidden"
                style={{ '--app-shell-height': SHELL_HEIGHT } as CSSProperties}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
