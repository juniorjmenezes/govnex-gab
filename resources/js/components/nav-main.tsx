import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

interface NavMainProps {
    label: string;
    items: NavItem[];
}

export function NavMain({ label, items }: NavMainProps) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-1">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        {item.disabled ? (
                            <SidebarMenuButton
                                aria-disabled="true"
                                className="cursor-default opacity-55 group-data-[collapsible=icon]:mx-auto"
                                tooltip={{
                                    children: `${item.title} — em breve`,
                                }}
                            >
                                {item.icon && <item.icon />}
                                <span className="min-w-0 truncate">
                                    {item.title}
                                </span>
                                <span className="ml-auto text-[10px] text-sidebar-foreground/60 group-data-[collapsible=icon]:hidden">
                                    Em breve
                                </span>
                            </SidebarMenuButton>
                        ) : (
                            <SidebarMenuButton
                                asChild
                                isActive={isCurrentUrl(item.href)}
                                className="group-data-[collapsible=icon]:mx-auto"
                                tooltip={{ children: item.title }}
                            >
                                <Link
                                    href={item.href}
                                    prefetch
                                    aria-current={
                                        isCurrentUrl(item.href)
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {item.icon && <item.icon />}
                                    <span className="min-w-0 truncate">
                                        {item.title}
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        )}
                        {item.badge != null && (
                            <SidebarMenuBadge className="rounded-full bg-primary/10 text-primary">
                                {item.badge}
                            </SidebarMenuBadge>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
