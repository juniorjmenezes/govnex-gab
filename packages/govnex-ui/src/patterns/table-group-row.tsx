import { TableCell, TableRow } from '../components/table';

export interface TableGroupRowProps {
    title: string;
    subtitle?: string;
    colSpan: number;
}

export function TableGroupRow({
    title,
    subtitle,
    colSpan,
}: TableGroupRowProps) {
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
