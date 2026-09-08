import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { OfficeTheme } from '@/components/layout/office-theme';
import { SidebarProvider } from '@/components/ui/sidebar';

type Props = {
    children: ReactNode;
};

export function AppShell({ children }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    return (
        <SidebarProvider defaultOpen={isOpen}>
            <OfficeTheme />
            {children}
        </SidebarProvider>
    );
}
