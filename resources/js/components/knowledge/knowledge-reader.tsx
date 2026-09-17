import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Document, Page, pdfjs } from 'react-pdf';
import 'react-pdf/dist/Page/AnnotationLayer.css';
import 'react-pdf/dist/Page/TextLayer.css';
import { EmptyState } from '@/components/feedback/empty-state';
import {
    AltArrowLeftIcon,
    AltArrowRightIcon,
    CheckCircleIcon,
    DocumentTextIcon,
    DownloadIcon,
    MagnifierZoomInIcon,
    MagnifierZoomOutIcon,
    PrinterIcon,
} from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import type { KnowledgeDocument } from '@/types';

pdfjs.GlobalWorkerOptions.workerSrc = new URL(
    'pdfjs-dist/build/pdf.worker.min.mjs',
    import.meta.url,
).toString();

const ZOOM_STEPS = [0.75, 1, 1.25, 1.5, 2];
/** Tempo com a página na tela para ela contar como lida. */
const DWELL_MS = 1500;
const MAX_PAGE_WIDTH = 960;
/** Páginas com canvas real renderizado ao redor da página ativa; as demais
 * ficam como espaço reservado até a rolagem se aproximar delas. */
const LOAD_WINDOW = 2;
/** Proporção usada para estimar a altura de páginas ainda não renderizadas
 * (A4 retrato), até medirmos a primeira página real. */
const DEFAULT_ASPECT_RATIO = 1.414;

/**
 * Leitor de PDF (pdf.js) com rolagem contínua entre páginas: todas as
 * páginas ficam em sequência vertical e a rolagem do mouse avança a
 * leitura, sem depender só dos botões. Um par de `IntersectionObserver`
 * cuida de duas coisas independentes: qual página está mais visível (para
 * o indicador, o zoom e o rastreamento de leitura) e quais páginas
 * próximas precisam ganhar canvas real (as demais ficam como espaço
 * reservado, para não renderizar centenas de páginas de uma vez).
 */
export function KnowledgeReader({
    document: knowledgeDocument,
    fileUrl,
    progressUrl,
    embedded = false,
}: {
    document: KnowledgeDocument;
    fileUrl: string;
    progressUrl: string;
    embedded?: boolean;
}) {
    const { progress } = knowledgeDocument;
    const [numPages, setNumPages] = useState<number | null>(
        knowledgeDocument.total_pages,
    );
    const [page, setPage] = useState(progress.last_page);
    const [pageInput, setPageInput] = useState(String(progress.last_page));
    const [zoomIndex, setZoomIndex] = useState(1);
    const [width, setWidth] = useState<number>();
    const [pageHeight, setPageHeight] = useState<number>();
    // Páginas vistas nesta sessão que o servidor ainda pode não ter confirmado.
    const [seenPages, setSeenPages] = useState<Set<number>>(() => new Set());
    const [loadedPages, setLoadedPages] = useState<Set<number>>(() => new Set());
    const [loadError, setLoadError] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const pageRefs = useRef<Map<number, HTMLDivElement>>(new Map());
    const lastSent = useRef<number | null>(null);
    const didInitialScroll = useRef(false);
    const numPagesRef = useRef(numPages);
    const activeObserverRef = useRef<IntersectionObserver | null>(null);
    const preloadObserverRef = useRef<IntersectionObserver | null>(null);

    useEffect(() => {
        numPagesRef.current = numPages;
    }, [numPages]);

    // Acompanha a largura disponível para a página caber no container.
    useEffect(() => {
        const element = containerRef.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.min(entry.contentRect.width - 32, MAX_PAGE_WIDTH)),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    // A altura estimada muda de escala com o zoom; remedimos na próxima
    // página que renderizar de verdade.
    useEffect(() => {
        setPageHeight(undefined);
    }, [zoomIndex, width]);

    const pagesRead = useMemo(
        () => new Set([...progress.pages_read, ...seenPages]),
        [progress.pages_read, seenPages],
    );

    // Sem dependências: os observers de rolagem são criados uma única vez
    // (montagem) e continuam chamando esta mesma função por toda a vida do
    // componente, então ela lê `numPages` via ref para nunca operar com um
    // valor antigo capturado no closure do observer.
    const expandLoadWindow = useCallback((start: number, end: number) => {
        const total = numPagesRef.current;

        if (!total) {
            return;
        }

        const from = Math.max(1, start - LOAD_WINDOW);
        const to = Math.min(total, end + LOAD_WINDOW);

        setLoadedPages((current) => {
            let changed = false;
            const next = new Set(current);

            for (let n = from; n <= to; n++) {
                if (!next.has(n)) {
                    next.add(n);
                    changed = true;
                }
            }

            return changed ? next : current;
        });
    }, []);

    const goTo = useCallback(
        (target: number) => {
            if (!numPages) {
                return;
            }

            const next = Math.min(Math.max(1, target), numPages);
            expandLoadWindow(next, next);
            pageRefs.current
                .get(next)
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setPage(next);
            setPageInput(String(next));
        },
        [numPages, expandLoadWindow],
    );

    const downloadPdf = useCallback(() => {
        const link = window.document.createElement('a');
        link.href = fileUrl;
        link.download = `${knowledgeDocument.title}.pdf`;
        window.document.body.appendChild(link);
        link.click();
        window.document.body.removeChild(link);
    }, [fileUrl, knowledgeDocument.title]);

    // Usa o visualizador nativo do navegador (via iframe oculto) em vez de
    // imprimir só o canvas renderizado — assim a impressão sai com o PDF
    // original completo, não limitada às páginas já carregadas na tela.
    const printPdf = useCallback(() => {
        const iframe = window.document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.src = fileUrl;

        iframe.onload = () => {
            iframe.contentWindow?.focus();
            iframe.contentWindow?.print();
        };

        window.document.body.appendChild(iframe);

        const cleanup = () => {
            window.document.body.removeChild(iframe);
            window.removeEventListener('focus', cleanup);
        };

        // O diálogo de impressão bloqueia o restante da página; removemos o
        // iframe quando o foco volta para a janela principal (diálogo
        // fechado, impresso ou cancelado).
        window.addEventListener('focus', cleanup, { once: true });
    }, [fileUrl]);

    // Carrega a janela inicial de páginas ao redor de onde a leitura parou.
    useEffect(() => {
        if (numPages) {
            expandLoadWindow(progress.last_page, progress.last_page);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [numPages]);

    // Cria os dois observers uma única vez, assim que o container de rolagem
    // existe: um decide qual página está mais visível (indicador, zoom,
    // rastreamento) e outro, com margem maior, adianta o carregamento das
    // páginas para as quais o usuário está se aproximando. Ficam guardados
    // em refs porque quem de fato os liga a cada página é o ref callback da
    // própria página (`registerPageRef`), no instante em que ela monta — não
    // um efeito dependente de `numPages`/`width`, que rodaria cedo demais
    // (antes das páginas existirem no DOM) e nunca mais seria re-executado.
    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const activeObserver = new IntersectionObserver(
            (entries) => {
                const best = entries.reduce<
                    { page: number; ratio: number } | null
                >((current, entry) => {
                    if (!entry.isIntersecting) {
                        return current;
                    }

                    const target = Number(
                        (entry.target as HTMLElement).dataset.page,
                    );

                    return !current || entry.intersectionRatio > current.ratio
                        ? { page: target, ratio: entry.intersectionRatio }
                        : current;
                }, null);

                if (best) {
                    setPage(best.page);
                    setPageInput(String(best.page));
                }
            },
            { root: container, threshold: [0.25, 0.5, 0.75, 1] },
        );

        const preloadObserver = new IntersectionObserver(
            (entries) => {
                const intersecting = entries
                    .filter((entry) => entry.isIntersecting)
                    .map((entry) =>
                        Number((entry.target as HTMLElement).dataset.page),
                    );

                if (intersecting.length === 0) {
                    return;
                }

                expandLoadWindow(
                    Math.min(...intersecting),
                    Math.max(...intersecting),
                );
            },
            { root: container, rootMargin: '600px 0px' },
        );

        activeObserverRef.current = activeObserver;
        preloadObserverRef.current = preloadObserver;

        // Cobre o caso raro de páginas que já tiverem montado no mesmo
        // commit em que este efeito roda (ex.: PDF já em cache).
        pageRefs.current.forEach((node) => {
            activeObserver.observe(node);
            preloadObserver.observe(node);
        });

        return () => {
            activeObserver.disconnect();
            preloadObserver.disconnect();
            activeObserverRef.current = null;
            preloadObserverRef.current = null;
        };
    }, [expandLoadWindow]);

    // Registra a página depois que ela ficou um tempo na tela.
    useEffect(() => {
        if (!numPages || lastSent.current === page) {
            return;
        }

        const timeout = window.setTimeout(() => {
            lastSent.current = page;
            setSeenPages((current) => new Set(current).add(page));
            router.post(
                progressUrl,
                { pagina: page, total_paginas: numPages },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: [],
                },
            );
        }, DWELL_MS);

        return () => window.clearTimeout(timeout);
    }, [page, numPages, progressUrl]);

    // Setas do teclado trocam de página, exceto quando se digita num campo.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (target?.closest('input, textarea, select, [contenteditable]')) {
                return;
            }

            if (event.key === 'ArrowRight') {
                goTo(page + 1);
            } else if (event.key === 'ArrowLeft') {
                goTo(page - 1);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [goTo, page]);

    const total = numPages ?? 0;
    const readCount = pagesRead.size;
    const percent =
        total > 0 ? Math.min(100, Math.floor((readCount * 100) / total)) : 0;
    const completed =
        progress.completed_at !== null || (total > 0 && readCount >= total);
    const scale = ZOOM_STEPS[zoomIndex];
    const estimatedHeight =
        pageHeight ?? (width ? width * DEFAULT_ASPECT_RATIO : undefined);

    // Observa a página no exato momento em que ela monta no DOM — em vez de
    // esperar um efeito rodar depois —, e resolve o salto inicial para a
    // página em que a leitura parou assim que ela aparecer.
    const registerPageRef = (n: number) => (node: HTMLDivElement | null) => {
        const previous = pageRefs.current.get(n);

        if (previous && previous !== node) {
            activeObserverRef.current?.unobserve(previous);
            preloadObserverRef.current?.unobserve(previous);
        }

        if (node) {
            pageRefs.current.set(n, node);
            activeObserverRef.current?.observe(node);
            preloadObserverRef.current?.observe(node);

            if (!didInitialScroll.current && n === progress.last_page) {
                didInitialScroll.current = true;
                node.scrollIntoView({ block: 'start' });
            }
        } else {
            pageRefs.current.delete(n);
        }
    };

    const content = (
        <>
            <div className="flex flex-col gap-3 border-b bg-card p-4 shrink-0 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Página anterior"
                        disabled={page <= 1}
                        onClick={() => goTo(page - 1)}
                    >
                        <AltArrowLeftIcon aria-hidden="true" />
                    </Button>
                    <form
                        noValidate
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        onSubmit={(event) => {
                            event.preventDefault();
                            goTo(Number(pageInput) || page);
                        }}
                    >
                        <Input
                            aria-label="Página atual"
                            inputMode="numeric"
                            className="w-16 text-center tabular-nums"
                            value={pageInput}
                            onChange={(event) =>
                                setPageInput(
                                    event.target.value.replace(/\D/g, ''),
                                )
                            }
                            onBlur={() => goTo(Number(pageInput) || page)}
                        />
                        <span className="whitespace-nowrap tabular-nums">
                            de {total || '—'}
                        </span>
                    </form>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Próxima página"
                        disabled={!numPages || page >= numPages}
                        onClick={() => goTo(page + 1)}
                    >
                        <AltArrowRightIcon aria-hidden="true" />
                    </Button>
                    <span className="mx-1 hidden h-6 w-px bg-border sm:block" />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Diminuir zoom"
                        disabled={zoomIndex === 0}
                        onClick={() => setZoomIndex(zoomIndex - 1)}
                    >
                        <MagnifierZoomOutIcon aria-hidden="true" />
                    </Button>
                    <span className="w-12 text-center text-sm text-muted-foreground tabular-nums">
                        {Math.round(scale * 100)}%
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Aumentar zoom"
                        disabled={zoomIndex === ZOOM_STEPS.length - 1}
                        onClick={() => setZoomIndex(zoomIndex + 1)}
                    >
                        <MagnifierZoomInIcon aria-hidden="true" />
                    </Button>
                    <span className="mx-1 hidden h-6 w-px bg-border sm:block" />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Baixar documento"
                        onClick={downloadPdf}
                        title="Baixar PDF"
                    >
                        <DownloadIcon aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Imprimir documento"
                        onClick={printPdf}
                        title="Imprimir PDF"
                    >
                        <PrinterIcon aria-hidden="true" />
                    </Button>
                </div>

                <div className="flex min-w-0 items-center gap-3 lg:w-80">
                    {completed ? (
                        <Badge className="shrink-0">
                            <CheckCircleIcon aria-hidden="true" />
                            Leitura concluída
                        </Badge>
                    ) : null}
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-xs text-muted-foreground tabular-nums">
                            {total > 0
                                ? `${readCount} de ${total} páginas lidas · ${percent}%`
                                : 'Carregando páginas…'}
                        </p>
                        <Progress
                            value={percent}
                            aria-label="Progresso de leitura"
                        />
                    </div>
                </div>
            </div>

            <div
                ref={containerRef}
                className={`overflow-y-auto bg-muted/40 p-4 ${embedded ? 'min-h-0 flex-1' : 'min-h-[60vh]'}`}
            >
                {loadError ? (
                    <EmptyState
                        icon={DocumentTextIcon}
                        title="Não foi possível abrir o PDF"
                        description="O arquivo pode estar corrompido ou indisponível. Tente novamente mais tarde."
                    />
                ) : (
                    <Document
                        file={fileUrl}
                        loading={
                            <div className="grid min-h-[60vh] place-items-center">
                                <Spinner />
                            </div>
                        }
                        onLoadSuccess={({ numPages: loaded }) =>
                            setNumPages(loaded)
                        }
                        onLoadError={() => setLoadError(true)}
                    >
                        {numPages && width ? (
                            <div
                                className="mx-auto flex flex-col items-center gap-4"
                                style={{ maxWidth: width }}
                            >
                                {Array.from(
                                    { length: numPages },
                                    (_, i) => i + 1,
                                ).map((n) => (
                                    <div
                                        key={n}
                                        ref={registerPageRef(n)}
                                        data-page={n}
                                        className="w-full"
                                        style={
                                            !loadedPages.has(n) &&
                                            estimatedHeight
                                                ? { height: estimatedHeight }
                                                : undefined
                                        }
                                    >
                                        {loadedPages.has(n) ? (
                                            <Page
                                                pageNumber={n}
                                                width={width}
                                                scale={scale}
                                                className="shadow-sm ring-1 ring-foreground/10"
                                                loading={
                                                    <div
                                                        className="grid place-items-center bg-background"
                                                        style={
                                                            estimatedHeight
                                                                ? {
                                                                      height: estimatedHeight,
                                                                  }
                                                                : undefined
                                                        }
                                                    >
                                                        <Spinner />
                                                    </div>
                                                }
                                                onRenderSuccess={() => {
                                                    if (pageHeight) {
                                                        return;
                                                    }

                                                    const node =
                                                        pageRefs.current.get(n);

                                                    if (node) {
                                                        setPageHeight(
                                                            node.getBoundingClientRect()
                                                                .height,
                                                        );
                                                    }
                                                }}
                                            />
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </Document>
                )}
            </div>
        </>
    );

    if (embedded) {
        return <div className="flex h-full flex-col">{content}</div>;
    }

    return <Card className="flex h-[75vh] flex-col gap-0 py-0">{content}</Card>;
}
