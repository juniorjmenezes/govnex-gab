import { AppLogoMark } from '@/components/app-logo-mark';
import { SettingsIcon } from '@/components/icons';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarBrand,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useAppNavSections } from '@/hooks/use-app-nav-sections';
import { useManagementNavItems } from '@/hooks/use-management-nav-items';

export function AppSidebar() {
    const sections = useAppNavSections();
    const managementItems = useManagementNavItems();

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-14 shrink-0 justify-center border-b border-sidebar-border px-2 py-0">
                <SidebarBrand
                    icon={<AppLogoMark className="size-6" aria-hidden="true" />}
                    name="GOVNEX GAB"
                />
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
                        icon={SettingsIcon}
                        flyout
                    />
                )}
            </SidebarContent>
            <SidebarFooter className="h-16 shrink-0 justify-center border-t border-sidebar-border px-2 py-0">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
