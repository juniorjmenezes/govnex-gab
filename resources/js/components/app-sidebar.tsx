import { AppLogoMark, AppWordmark } from '@/components/app-logo-mark';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useAppNavSections } from '@/hooks/use-app-nav-sections';

export function AppSidebar() {
    const sections = useAppNavSections();

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-14 shrink-0 justify-center bg-sidebar-header px-2 py-0 text-sidebar-header-foreground">
                <div className="flex items-center justify-between gap-2 px-3 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
                    <AppLogoMark
                        className="h-8 w-auto shrink-0"
                        aria-hidden="true"
                    />
                    <AppWordmark />
                </div>
            </SidebarHeader>
            <SidebarContent className="min-h-0 overflow-y-auto py-2">
                {sections.map((section) => (
                    <NavMain
                        key={section.label}
                        label={section.label}
                        items={section.items}
                    />
                ))}
            </SidebarContent>
            <SidebarFooter className="h-14 shrink-0 justify-center border-t border-sidebar-border px-2 py-0">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
