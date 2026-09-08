import type { InertiaLinkProps } from '@inertiajs/react';
import type { IconComponent } from '@/types/icon';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: IconComponent | null;
    isActive?: boolean;
    disabled?: boolean;
    badge?: number | string;
};
