import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { MagnifierIcon } from '@/components/icons';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useAppNavSections } from '@/hooks/use-app-nav-sections';
import { useManagementNavItems } from '@/hooks/use-management-nav-items';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

type SearchResult = NavItem & { section: string };

// Rótulo do atalho de teclado conforme a plataforma (sem SSR neste app).
const shortcutLabel =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent)
        ? '⌘K'
        : 'Ctrl K';

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

export function GlobalSearch() {
    const sections = useAppNavSections();
    const managementItems = useManagementNavItems();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    // Cmd+K / Ctrl+K leva o foco à busca de qualquer ponto da aplicação.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                inputRef.current?.focus();
                inputRef.current?.select();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const allItems = useMemo<SearchResult[]>(
        () => [
            ...sections.flatMap((section) =>
                section.items.map((item) => ({
                    ...item,
                    section: section.label,
                })),
            ),
            ...managementItems.map((item) => ({
                ...item,
                section: 'Gestão do gabinete',
            })),
        ],
        [sections, managementItems],
    );

    const results = useMemo(() => {
        const term = normalize(query.trim());

        if (term === '') {
            return [];
        }

        return allItems.filter((item) => normalize(item.title).includes(term));
    }, [allItems, query]);

    const navigateTo = (href: NavItem['href']) => {
        setOpen(false);
        setQuery('');
        router.visit(href);
    };

    return (
        <Popover open={open && results.length > 0}>
            <PopoverAnchor asChild>
                <div className="relative hidden w-full max-w-56 sm:block lg:max-w-72">
                    <MagnifierIcon
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        ref={inputRef}
                        type="search"
                        role="combobox"
                        aria-expanded={open && results.length > 0}
                        aria-label="Buscar páginas do sistema"
                        aria-keyshortcuts="Meta+K Control+K"
                        placeholder="Buscar página…"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                setOpen(false);
                                event.currentTarget.blur();
                            }

                            if (event.key === 'Enter' && results[0]) {
                                event.preventDefault();
                                navigateTo(results[0].href);
                            }
                        }}
                        onBlur={() =>
                            window.setTimeout(() => setOpen(false), 100)
                        }
                        className="peer h-9 w-full rounded-md border border-input bg-muted pr-16 pl-8 text-sm outline-none placeholder:text-muted-foreground hover:border-[color-mix(in_oklch,var(--input),var(--foreground)_12%)] focus-visible:border-[color-mix(in_oklch,var(--input),var(--foreground)_25%)] [&::-webkit-search-cancel-button]:hidden"
                    />
                    <kbd
                        className="pointer-events-none absolute top-1/2 right-2 hidden -translate-y-1/2 items-center rounded-sm border bg-muted px-1.5 font-sans text-[11px] leading-5 font-medium text-muted-foreground peer-focus-visible:opacity-0 sm:inline-flex"
                        aria-hidden="true"
                    >
                        {shortcutLabel}
                    </kbd>
                </div>
            </PopoverAnchor>
            <PopoverContent
                align="start"
                onOpenAutoFocus={(event) => event.preventDefault()}
                className="w-[min(20rem,calc(100vw-2rem))] p-1"
            >
                <ul className="flex max-h-80 flex-col gap-0.5 overflow-y-auto">
                    {results.map((item) => (
                        <li key={`${item.section}-${item.title}`}>
                            <Link
                                href={item.href}
                                prefetch
                                onClick={() => {
                                    setOpen(false);
                                    setQuery('');
                                }}
                                className={cn(
                                    'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                {item.icon && (
                                    <item.icon
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                                <span className="min-w-0 flex-1 truncate">
                                    {item.title}
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {item.section}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
