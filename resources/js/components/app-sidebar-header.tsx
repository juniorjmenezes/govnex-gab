import { Breadcrumbs } from '@/components/breadcrumbs';
import { AppMenubar } from '@/components/layout/app-menubar';
import { GlobalSearch } from '@/components/layout/global-search';
import { NotificationCenter } from '@/components/layout/notification-center';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { useManagementNavItems } from '@/hooks/use-management-nav-items';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { state } = useSidebar();
    const managementItems = useManagementNavItems();
    const hasMenubar = managementItems.length > 0;

    return (
        <>
            <div
                className={cn(
                    'fixed inset-x-0 top-0 z-30 transition-[left] duration-200 ease-linear md:left-(--sidebar-width)',
                    state === 'collapsed' && 'md:left-(--sidebar-width-icon)',
                )}
            >
                <header className="flex h-14 items-center justify-between gap-3 border-b bg-card/95 px-4 backdrop-blur-sm sm:px-6">
                    <div className="flex min-w-0 items-center gap-2">
                        <SidebarTrigger className="-ml-1" />
                        <Separator
                            orientation="vertical"
                            className="data-vertical:h-4 data-vertical:self-center"
                        />
                        <div className="min-w-0">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <GlobalSearch />
                        <div className="flex items-center gap-1">
                            <ThemeToggle />
                            <NotificationCenter />
                        </div>
                    </div>
                </header>
                {hasMenubar && <AppMenubar items={managementItems} />}
            </div>
            <div
                className={cn('shrink-0', hasMenubar ? 'h-24' : 'h-14')}
                aria-hidden="true"
            />
        </>
    );
}
