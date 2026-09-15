import { TableCell, TableRow } from '@/components/ui/table';

/**
 * Linha que divide uma tabela em blocos, ocupando a largura inteira. É a
 * forma padrão de separar assuntos dentro de uma tabela — em vez de quebrar
 * em várias tabelas, cada uma com seu cabeçalho, o que faz as colunas
 * perderem o alinhamento entre os blocos.
 *
 * O subtítulo entra na mesma linha, depois do título, separado por "·":
 * contexto curto (contagem, turno) sem roubar a linha do título.
 */
export function TableGroupRow({
    title,
    subtitle,
    colSpan,
}: {
    title: string;
    subtitle?: string;
    colSpan: number;
}) {
    return (
        <TableRow className="bg-muted/30 hover:bg-muted/30">
            <TableCell
                colSpan={colSpan}
                className="py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {title}
                {subtitle ? ` · ${subtitle}` : null}
            </TableCell>
        </TableRow>
    );
}
