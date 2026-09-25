import { Breadcrumbs } from '@/components/breadcrumbs';
import { GlobalSearch } from '@/components/layout/global-search';
import { NotificationCenter } from '@/components/layout/notification-center';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { state } = useSidebar();
    // Com um único nível a trilha só repetiria o título da página (que o
    // `PageHeader` já mostra); ela aparece apenas em telas aninhadas.
    const showBreadcrumbs = breadcrumbs.length > 1;

    return (
        <>
            <div
                className={cn(
                    'fixed inset-x-0 top-0 z-30 transition-[left] duration-200 ease-linear md:left-(--sidebar-width)',
                    state === 'collapsed' && 'md:left-(--sidebar-width-icon)',
                )}
            >
                <header className="flex h-14 items-center justify-between gap-3 border-b bg-background/85 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/70 sm:px-6">
                    <div className="flex min-w-0 items-center gap-2">
                        <SidebarTrigger className="-ml-1" />
                        {showBreadcrumbs && (
                            <>
                                <Separator
                                    orientation="vertical"
                                    className="data-vertical:h-4 data-vertical:self-center"
                                />
                                <div className="min-w-0">
                                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                                </div>
                            </>
                        )}
                    </div>
                    <div className="flex min-w-0 flex-1 items-center justify-end gap-2">
                        <GlobalSearch />
                        <div className="flex items-center gap-1">
                            <ThemeToggle />
                            <NotificationCenter />
                        </div>
                    </div>
                </header>
            </div>
            {/* Compensa o cabeçalho fixo. A altura vem da variável definida no
                layout, a mesma que os mapas descontam de 100svh — assim as
                duas medidas não têm como divergir. */}
            <div
                className="shrink-0"
                style={{ height: 'var(--app-shell-height, 3.5rem)' }}
                aria-hidden="true"
            />
        </>
    );
}
