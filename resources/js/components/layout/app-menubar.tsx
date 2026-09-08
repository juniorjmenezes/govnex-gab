import { Link, usePage } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { Auth, NavItem } from '@/types';

/**
 * Barra secundária logo abaixo da topbar, com os itens de gestão que antes
 * ficavam escondidos num dropdown no cabeçalho. À direita, o gabinete em que
 * a sessão está trabalhando.
 */
export function AppMenubar({ items }: { items: NavItem[] }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isCurrentUrl } = useCurrentUrl();
    const officeName = auth.context.gabinete?.name ?? auth.user.gabinete?.nome;

    return (
        <div className="flex h-10 items-center gap-4 border-b bg-card px-4 sm:px-6">
            <nav
                aria-label="Gestão"
                className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto"
            >
                {items.map((item) => {
                    const active = isCurrentUrl(item.href);

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex shrink-0 items-center gap-1.5 rounded-sm px-2.5 py-1.5 text-xs font-medium tracking-wide whitespace-nowrap uppercase transition-colors',
                                active
                                    ? 'bg-accent font-semibold text-foreground'
                                    : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                            )}
                        >
                            {item.icon && (
                                <item.icon
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            )}
                            {item.title}
                        </Link>
                    );
                })}
            </nav>
            {officeName && (
                <span className="max-w-[40%] shrink-0 truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {officeName}
                </span>
            )}
        </div>
    );
}
