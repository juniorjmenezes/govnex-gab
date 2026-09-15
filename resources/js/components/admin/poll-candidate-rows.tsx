import { TableActionButton } from '@/components/common/table-action-button';
import { TrashBinTrashIcon } from '@/components/icons';
import { AppSelect } from '@/components/ui/app-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CandidateRowInput } from '@/lib/poll-curation';
import type { PollCurationCandidateOption } from '@/types';

type Props = {
    rows: CandidateRowInput[];
    onChange: (rows: CandidateRowInput[]) => void;
    candidateOptions: PollCurationCandidateOption[];
    loadingCandidateOptions?: boolean;
    error?: string;
};

export function PollCandidateRows({
    rows,
    onChange,
    candidateOptions,
    loadingCandidateOptions,
    error,
}: Props) {
    const updateRow = (key: string, patch: Partial<CandidateRowInput>) => {
        onChange(
            rows.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );
    };
    const removeRow = (key: string) => {
        onChange(rows.filter((row) => row.key !== key));
    };
    const candidateSelectOptions = candidateOptions.map((option) => ({
        value: String(option.id),
        label: `${option.name}${option.party ? ` (${option.party})` : ''}${
            option.number ? ` — nº ${option.number}` : ''
        }`,
    }));

    return (
        <div className="space-y-3">
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="space-y-3">
                {rows.map((row) => (
                    <div
                        key={row.key}
                        className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[2fr_1fr_1fr_2fr_auto] sm:items-end"
                    >
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">
                                Nome
                            </Label>
                            <Input
                                value={row.nome}
                                onChange={(event) =>
                                    updateRow(row.key, {
                                        nome: event.target.value,
                                    })
                                }
                                placeholder="Nome do candidato"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">
                                Partido
                            </Label>
                            <Input
                                value={row.partido}
                                onChange={(event) =>
                                    updateRow(row.key, {
                                        partido: event.target.value,
                                    })
                                }
                                placeholder="PT, PL..."
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">
                                %
                            </Label>
                            <Input
                                type="number"
                                min={0}
                                max={100}
                                step="0.1"
                                value={row.percentual}
                                onChange={(event) =>
                                    updateRow(row.key, {
                                        percentual: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">
                                Vincular a candidato cadastrado (opcional)
                            </Label>
                            <AppSelect
                                value={
                                    row.candidato_politico_id
                                        ? String(row.candidato_politico_id)
                                        : ''
                                }
                                onValueChange={(value) =>
                                    updateRow(row.key, {
                                        candidato_politico_id: value
                                            ? Number(value)
                                            : null,
                                    })
                                }
                                options={candidateSelectOptions}
                                emptyLabel="Nenhum vínculo"
                                placeholder={
                                    loadingCandidateOptions
                                        ? 'Carregando...'
                                        : 'Selecionar'
                                }
                                disabled={candidateSelectOptions.length === 0}
                            />
                        </div>
                        <div className="flex justify-end">
                            <TableActionButton
                                type="button"
                                variant="destructive"
                                label={
                                    row.nome.trim()
                                        ? `Remover ${row.nome.trim()}`
                                        : 'Remover candidato'
                                }
                                onClick={() => removeRow(row.key)}
                            >
                                <TrashBinTrashIcon aria-hidden="true" />
                            </TableActionButton>
                        </div>
                    </div>
                ))}
                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nenhum candidato adicionado ainda.
                    </p>
                )}
            </div>
        </div>
    );
}
