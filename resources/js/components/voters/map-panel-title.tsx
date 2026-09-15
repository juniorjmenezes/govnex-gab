/**
 * Título do painel flutuante dos mapas (eleitores e prospecção). O painel é
 * estreito: título e descrição ficam empilhados, sem o separador "·" da
 * PageHeader, que sobrava sozinho quando a descrição quebrava de linha.
 */
export function MapPanelTitle({
    title,
    description,
}: {
    title: string;
    description?: string;
}) {
    return (
        <div className="flex flex-col">
            <div className="flex min-w-0 flex-wrap items-baseline">
                <h1 className="max-w-full min-w-0 text-lg font-bold text-foreground">
                    {title}
                </h1>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
        </div>
    );
}
