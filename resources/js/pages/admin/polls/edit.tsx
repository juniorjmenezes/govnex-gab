import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PollCandidateRows } from '@/components/admin/poll-candidate-rows';
import { FieldError } from '@/components/forms/field-error';
import { AddIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { usePollCandidateOptions } from '@/hooks/use-poll-candidate-options';
import {
    CARGO_OPTIONS,
    CENARIO_LABELS,
    emptyCandidateRow,
} from '@/lib/poll-curation';
import type { CandidateRowInput } from '@/lib/poll-curation';
import type { PollCurationPesquisa } from '@/types';

const cargoLabels: Record<string, string> = Object.fromEntries(
    CARGO_OPTIONS.map((option) => [option.value, option.label]),
);

const formatDate = (value: string) =>
    new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR');

type ResultsErrors = Partial<
    Record<'provider' | 'confidence_score' | 'url' | 'observacao', string>
>;

/**
 * Edição dos resultados de uma pesquisa já registrada. Cada gravação entra
 * como nova fonte manual: o backend só aplica os números se a confiança for
 * maior ou igual à atual; senão guarda o registro apenas para auditoria.
 */
export default function EditPoll({
    pesquisa,
}: {
    pesquisa: PollCurationPesquisa;
}) {
    const [rows, setRows] = useState<CandidateRowInput[]>(() =>
        pesquisa.resultados.length > 0
            ? pesquisa.resultados.map((result) => ({
                  key: result.external_candidate_id,
                  nome: result.nome,
                  partido: result.partido ?? '',
                  percentual: String(result.percentual),
                  candidato_politico_id: result.candidato_politico_id,
              }))
            : [emptyCandidateRow()],
    );
    // A proveniência começa pela fonte manual que está aplicada (a que deu
    // origem e confiança atuais), não pela última gravada: a última pode ser
    // uma tentativa recusada, de confiança menor, e repeti-la seria recusada
    // de novo. Fontes automáticas não preenchem — uma correção manual não
    // deve ser atribuída a elas. As fontes chegam da mais recente à mais
    // antiga.
    const manualSources = pesquisa.fontes.filter(
        (fonte) => fonte.tipo === 'manual',
    );
    const appliedManualSource =
        manualSources.find(
            (fonte) =>
                fonte.provider === pesquisa.origem_provider &&
                fonte.confidence_score === pesquisa.confianca,
        ) ??
        manualSources[0] ??
        null;
    const [provider, setProvider] = useState(
        appliedManualSource?.provider ?? '',
    );
    // A confiança parte da atual: é o mínimo para a correção valer.
    const [confidenceScore, setConfidenceScore] = useState(
        String(
            pesquisa.confianca ?? appliedManualSource?.confidence_score ?? 90,
        ),
    );
    const [url, setUrl] = useState(pesquisa.fonte_url ?? '');
    const [observacao, setObservacao] = useState(
        appliedManualSource?.observacao ?? '',
    );
    const belowCurrentConfidence =
        pesquisa.confianca !== null &&
        confidenceScore !== '' &&
        Number(confidenceScore) < pesquisa.confianca;
    const [errors, setErrors] = useState<ResultsErrors>({});
    const [candidatesError, setCandidatesError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const { options: candidateOptions, loading: loadingCandidates } =
        usePollCandidateOptions({
            eleicaoId: pesquisa.eleicao_id,
            cargo: pesquisa.cargo,
            uf: pesquisa.cargo === 'presidente' ? '' : pesquisa.uf,
            municipio: pesquisa.municipio ?? '',
        });

    const territory = `${pesquisa.uf}${pesquisa.municipio ? `/${pesquisa.municipio}` : ''}`;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const cleanedRows = rows.filter((row) => row.nome.trim() !== '');
        const providerMissing = provider.trim() === '';
        const candidatesMissing = cleanedRows.length === 0;

        // Valida antes de enviar, mostrando o erro no campo: com o botão
        // desativado, quem só vinculava candidatos não via o que faltava.
        setErrors(
            providerMissing
                ? {
                      provider:
                          'Descreva a fonte (ex.: "AtlasIntel — PDF oficial").',
                  }
                : {},
        );
        setCandidatesError(
            candidatesMissing ? 'Adicione ao menos um candidato.' : '',
        );

        if (providerMissing || candidatesMissing) {
            if (providerMissing) {
                document.getElementById('provider')?.focus();
            }

            return;
        }

        setSubmitting(true);
        router.post(
            `/admin/pesquisas-eleitorais/${pesquisa.id}/resultados`,
            {
                provider,
                confidence_score: Number(confidenceScore),
                url: url || null,
                observacao: observacao || null,
                candidatos: cleanedRows.map((row) => ({
                    nome: row.nome.trim(),
                    partido: row.partido.trim() || null,
                    percentual: row.percentual,
                    candidato_politico_id: row.candidato_politico_id,
                })),
            },
            {
                onError: (serverErrors) => {
                    const fieldErrors: ResultsErrors = {};

                    Object.entries(serverErrors).forEach(([key, message]) => {
                        if (key.startsWith('candidatos')) {
                            setCandidatesError(message);

                            return;
                        }

                        fieldErrors[key as keyof ResultsErrors] = message;
                    });
                    setErrors(fieldErrors);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <Head title="Editar pesquisa eleitoral" />
            <PageContainer>
                <PageHeader
                    title="Editar resultados"
                    description={`${pesquisa.instituto ?? 'Instituto não informado'} · ${cargoLabels[pesquisa.cargo] ?? pesquisa.cargo} · ${territory}`}
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>Pesquisa</SurfaceTitle>
                            <SurfaceDescription>
                                Publicada em {formatDate(pesquisa.publicada_em)}
                            </SurfaceDescription>
                        </SurfaceHeader>
                        <dl className="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Cenário">
                                {CENARIO_LABELS[pesquisa.cenario] ??
                                    pesquisa.cenario}
                            </Detail>
                            <Detail label="Turno">
                                {pesquisa.turno}º turno
                            </Detail>
                            <Detail label="Origem atual">
                                {pesquisa.origem_provider === 'manual'
                                    ? 'Curadoria manual'
                                    : (pesquisa.origem_provider ??
                                      'Não informada')}
                            </Detail>
                            <Detail label="Confiança atual">
                                {pesquisa.confianca ?? 'Não definida'}
                            </Detail>
                        </dl>
                    </Card>

                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="Descreva de onde os números vieram e o quanto confia neles.">
                            <SurfaceTitle>Proveniência</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-5 p-5">
                            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                {pesquisa.confianca === null
                                    ? 'Esta pesquisa ainda não tem confiança definida: os números informados aqui passam a valer.'
                                    : `Os números só substituem os atuais com confiança maior ou igual a ${pesquisa.confianca}. Abaixo disso, o registro fica apenas como auditoria.`}
                            </p>
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="provider">
                                        Fonte <span aria-hidden="true">*</span>
                                    </Label>
                                    <Input
                                        id="provider"
                                        value={provider}
                                        placeholder='Ex.: "AtlasIntel — PDF oficial"'
                                        aria-required="true"
                                        onChange={(event) =>
                                            setProvider(event.target.value)
                                        }
                                        aria-invalid={Boolean(errors.provider)}
                                    />
                                    <FieldError message={errors.provider} />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="confidence_score">
                                        Confiança (1-100)
                                    </Label>
                                    <Input
                                        id="confidence_score"
                                        type="number"
                                        min={1}
                                        max={100}
                                        value={confidenceScore}
                                        onChange={(event) =>
                                            setConfidenceScore(
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            errors.confidence_score,
                                        )}
                                    />
                                    <FieldError
                                        message={errors.confidence_score}
                                    />
                                    {belowCurrentConfidence &&
                                        !errors.confidence_score && (
                                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                                Abaixo da confiança atual (
                                                {pesquisa.confianca}): os
                                                números ficarão só como
                                                auditoria, sem alterar a
                                                pesquisa.
                                            </p>
                                        )}
                                </div>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="url">
                                    URL da fonte (opcional)
                                </Label>
                                <Input
                                    id="url"
                                    type="url"
                                    value={url}
                                    onChange={(event) =>
                                        setUrl(event.target.value)
                                    }
                                    aria-invalid={Boolean(errors.url)}
                                />
                                <FieldError message={errors.url} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="observacao">
                                    Observação (opcional)
                                </Label>
                                <Textarea
                                    id="observacao"
                                    value={observacao}
                                    onChange={(event) =>
                                        setObservacao(event.target.value)
                                    }
                                    aria-invalid={Boolean(errors.observacao)}
                                />
                                <FieldError message={errors.observacao} />
                            </div>
                        </div>
                    </Card>

                    <Card className="gap-0 py-0">
                        <SurfaceHeader
                            actions={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0"
                                    onClick={() =>
                                        setRows([...rows, emptyCandidateRow()])
                                    }
                                >
                                    <AddIcon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Adicionar candidato
                                </Button>
                            }
                        >
                            <SurfaceTitle>
                                Candidatos e percentuais
                            </SurfaceTitle>
                        </SurfaceHeader>
                        <div className="p-5">
                            <PollCandidateRows
                                rows={rows}
                                onChange={setRows}
                                candidateOptions={candidateOptions}
                                loadingCandidateOptions={loadingCandidates}
                                error={candidatesError}
                            />
                        </div>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.get('/admin/pesquisas-eleitorais')
                            }
                        >
                            Cancelar
                        </Button>
                        <Button disabled={submitting}>Salvar resultados</Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}

EditPoll.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        {
            title: 'Pesquisas eleitorais',
            href: '/admin/pesquisas-eleitorais',
        },
        { title: 'Editar resultados', href: '#' },
    ],
};
