import { router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { FieldLabel } from '@/components/forms/field-label';
import { Field } from '@/components/ui/field';
import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Pagination as Paginator } from '@/types';

const PER_PAGE_OPTIONS = [15, 30, 50];

/**
 * Rodapé das listagens: contagem, quantidade por página e anterior/próxima.
 * O Laravel devolve `links` com o "anterior" na primeira posição e o
 * "próxima" na última — é de lá que saem as duas URLs, sem precisar dos
 * números de página no meio.
 */
export function PaginationLinks<T>({
    pagination,
    label,
}: {
    pagination: Paginator<T>;
    /** Substantivo contado, já no plural do rodapé: "cidadão(s)". */
    label: string;
}) {
    const { links, from, to, total } = pagination;
    const previousUrl = links.at(0)?.url ?? null;
    const nextUrl = links.at(-1)?.url ?? null;
    // Nem todo controller devolve o paginador cru: alguns montam o payload à
    // mão. Sem esta guarda, um `per_page` ausente derruba a página inteira no
    // `toString()` em vez de só esconder o seletor.
    const perPage =
        typeof pagination.per_page === 'number' ? pagination.per_page : null;

    const visit = (url: string | null) => (event: MouseEvent) => {
        event.preventDefault();

        if (url) {
            router.visit(url, { preserveScroll: true, preserveState: true });
        }
    };

    const changePerPage = (value: string) => {
        router.visit(window.location.pathname, {
            data: {
                ...Object.fromEntries(
                    new URLSearchParams(window.location.search),
                ),
                per_page: value,
                page: 1,
            },
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <>
            <div className="border-t px-4 py-3 text-xs text-muted-foreground">
                Exibindo {from}–{to} de {total} {label}
            </div>
            <div className="flex items-center justify-between gap-4 border-t p-4">
                {perPage === null ? (
                    <span />
                ) : (
                    <Field orientation="horizontal" className="w-fit">
                        <FieldLabel htmlFor="select-rows-per-page">
                            Por página
                        </FieldLabel>
                        <Select
                            value={perPage.toString()}
                            onValueChange={changePerPage}
                        >
                            <SelectTrigger
                                className="w-20"
                                id="select-rows-per-page"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent align="start">
                                <SelectGroup>
                                    {[
                                        ...new Set([
                                            ...PER_PAGE_OPTIONS,
                                            perPage,
                                        ]),
                                    ]
                                        .sort((a, b) => a - b)
                                        .map((option) => (
                                            <SelectItem
                                                key={option}
                                                value={option.toString()}
                                            >
                                                {option}
                                            </SelectItem>
                                        ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </Field>
                )}
                <Pagination className="mx-0 w-auto">
                    <PaginationContent>
                        <PaginationItem>
                            <PaginationPrevious
                                href={previousUrl ?? '#'}
                                text="Anterior"
                                aria-disabled={previousUrl === null}
                                className={cn(
                                    previousUrl === null &&
                                        'pointer-events-none opacity-50',
                                )}
                                onClick={visit(previousUrl)}
                            />
                        </PaginationItem>
                        <PaginationItem>
                            <PaginationNext
                                href={nextUrl ?? '#'}
                                text="Próxima"
                                aria-disabled={nextUrl === null}
                                className={cn(
                                    nextUrl === null &&
                                        'pointer-events-none opacity-50',
                                )}
                                onClick={visit(nextUrl)}
                            />
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            </div>
        </>
    );
}
