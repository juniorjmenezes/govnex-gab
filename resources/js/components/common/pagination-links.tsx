import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { PaginationLink } from '@/types';

export function PaginationLinks({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav
            aria-label="Paginação"
            className="flex flex-wrap justify-end gap-1 border-t p-4"
        >
            {links.map((link, index) =>
                link.url ? (
                    <Button
                        key={index}
                        asChild
                        size="sm"
                        variant={link.active ? 'default' : 'outline'}
                    >
                        <Link
                            href={link.url}
                            preserveScroll
                            aria-current={link.active ? 'page' : undefined}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    </Button>
                ) : (
                    <Button
                        key={index}
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
