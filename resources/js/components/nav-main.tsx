import { Link } from '@inertiajs/react';
import { FolderIcon } from '@/components/icons';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

interface NavMainProps {
    label: string;
    items: NavItem[];
    icon?: React.ComponentType<{ className?: string }>;
    /**
     * Com a sidebar recolhida, vira um único botão (ícone do grupo) que
     * abre os itens num menu flutuante, em vez de empilhar um ícone por
     * item — usado só no grupo "Gestão do gabinete", que concentra itens
     * administrativos e tende a crescer.
     */
    flyout?: boolean;
}

export function NavMain({
    label,
    items,
    icon: GroupIcon,
    flyout = false,
}: NavMainProps) {
    const { isCurrentUrl } = useCurrentUrl();
    const { state } = useSidebar();

    if (flyout && state === 'collapsed' && items.length > 0) {
        const Icon = GroupIcon ?? FolderIcon;

        return (
            <SidebarGroup className="px-2 py-1">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton
                                    className="mx-auto"
                                    tooltip={{ children: label }}
                                >
                                    <Icon />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                side="right"
                                align="start"
                                className="min-w-56"
                            >
                                <DropdownMenuLabel>{label}</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {items.map((item) => (
                                    <DropdownMenuItem
                                        key={item.title}
                                        asChild={!item.disabled}
                                        disabled={item.disabled}
                                    >
                                        {item.disabled ? (
                                            <span className="flex w-full items-center gap-2">
                                                {item.icon && <item.icon />}
                                                <span className="min-w-0 truncate">
                                                    {item.title}
                                                </span>
                                                <span className="ml-auto text-[10px] text-muted-foreground">
                                                    Em breve
                                                </span>
                                            </span>
                                        ) : (
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
                                        )}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        );
    }

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
