import { usePage } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo-mark';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useAppNavSections } from '@/hooks/use-app-nav-sections';
import { useManagementNavItems } from '@/hooks/use-management-nav-items';
import type { Auth } from '@/types';

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const sections = useAppNavSections();
    const managementItems = useManagementNavItems();
    // O nome do gabinete (antes à direita da faixa de gestão) identifica o
    // espaço de trabalho no topo da sidebar; sem gabinete (root na visão da
    // plataforma), fica o nome do produto.
    const officeName =
        auth.context.gabinete?.name ?? auth.user.gabinete?.nome ?? null;

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-14 shrink-0 justify-center border-b border-sidebar-border px-2 py-0">
                <div className="flex min-w-0 items-center gap-2.5 px-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary/10 text-sidebar-primary ring-1 ring-sidebar-primary/15">
                        <AppLogoMark className="size-4.5" aria-hidden="true" />
                    </span>
                    <div className="flex min-w-0 flex-col leading-tight group-data-[collapsible=icon]:hidden">
                        <span
                            className="truncate text-sm font-semibold text-sidebar-foreground"
                            title={officeName ?? undefined}
                        >
                            {officeName ?? 'GOVNEX GAB'}
                        </span>
                        <span className="truncate text-xs text-sidebar-foreground/70">
                            {officeName ? 'GOVNEX GAB' : 'Plataforma'}
                        </span>
                    </div>
                </div>
            </SidebarHeader>
            <SidebarContent className="min-h-0 gap-1 overflow-y-auto py-2">
                {sections.map((section) => (
                    <NavMain
                        key={section.label}
                        label={section.label}
                        items={section.items}
                    />
                ))}
                {managementItems.length > 0 && (
                    <NavMain
                        label="Gestão do gabinete"
                        items={managementItems}
                    />
                )}
            </SidebarContent>
            <SidebarFooter className="h-16 shrink-0 justify-center border-t border-sidebar-border px-2 py-0">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
