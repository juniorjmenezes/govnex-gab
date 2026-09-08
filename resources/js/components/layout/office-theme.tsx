import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { Auth } from '@/types';

const themeProperties = [
    '--primary',
    '--primary-foreground',
    '--ring',
    '--sidebar-primary',
    '--sidebar-primary-foreground',
    '--sidebar-ring',
] as const;

function readableForeground(hex: string): string {
    const channels = hex
        .slice(1)
        .match(/.{2}/g)
        ?.map((channel) => Number.parseInt(channel, 16) / 255);

    if (!channels) {
        return '#ffffff';
    }

    const [red, green, blue] = channels.map((channel) =>
        channel <= 0.03928
            ? channel / 12.92
            : Math.pow((channel + 0.055) / 1.055, 2.4),
    );
    const luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue;

    return luminance > 0.48 ? '#111111' : '#ffffff';
}

export function OfficeTheme() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const color =
        auth.context.gabinete?.primary_color ??
        auth.context.entidade?.primary_color ??
        auth.user?.gabinete?.cor_principal;

    useEffect(() => {
        if (!color || !/^#[0-9A-Fa-f]{6}$/.test(color)) {
            return;
        }

        const root = document.documentElement;
        const foreground = readableForeground(color);
        const values = {
            '--primary': color,
            '--primary-foreground': foreground,
            '--ring': color,
            '--sidebar-primary': color,
            '--sidebar-primary-foreground': foreground,
            '--sidebar-ring': color,
        };

        Object.entries(values).forEach(([property, value]) =>
            root.style.setProperty(property, value),
        );

        const themeMeta = document.querySelector<HTMLMetaElement>(
            'meta[name="theme-color"]',
        );
        const previousThemeColor = themeMeta?.content;

        if (themeMeta) {
            themeMeta.content = color;
        }

        return () => {
            themeProperties.forEach((property) =>
                root.style.removeProperty(property),
            );

            if (themeMeta && previousThemeColor) {
                themeMeta.content = previousThemeColor;
            }
        };
    }, [color]);

    return null;
}
