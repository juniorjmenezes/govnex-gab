import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { PollCandidateRows } from '@/components/admin/poll-candidate-rows';
import { FieldError } from '@/components/forms/field-error';
import { AddIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { usePollCandidateOptions } from '@/hooks/use-poll-candidate-options';
import {
    CARGO_OPTIONS,
    CARGOS_MUNICIPAIS,
    CENARIO_OPTIONS,
    TURNO_OPTIONS,
    UF_OPTIONS,
    emptyCandidateRow,
} from '@/lib/poll-curation';
import type { CandidateRowInput } from '@/lib/poll-curation';
import type { PollCurationElection } from '@/types';

type Props = {
    elections: PollCurationElection[];
};

const schema = z
    .object({
        eleicao_id: z.string().min(1, 'Selecione a eleição.'),
        cargo: z.string().min(1, 'Selecione o cargo.'),
        uf: z.string(),
        municipio: z.string(),
        turno: z.string().min(1, 'Selecione o turno.'),
        cenario: z.string().min(1, 'Selecione o cenário.'),
        instituto: z.string().min(1, 'Informe o instituto.'),
        publicada_em: z.string().min(1, 'Informe a data de publicação.'),
        coleta_inicio_em: z.string(),
        coleta_fim_em: z.string(),
        tamanho_amostra: z.string(),
        margem_erro: z.string(),
        metodologia: z.string(),
        abrangencia: z.string(),
        tipo: z.string(),
        fonte_url: z.url('Informe uma URL válida.'),
        provider: z
            .string()
            .min(1, 'Descreva a fonte (ex.: "AtlasIntel — PDF oficial").'),
        confidence_score: z.string().min(1, 'Informe a confiança.'),
        observacao: z.string(),
    })
    // UF e município só aparecem para alguns cargos: validá-los sempre
    // barrava o envio com erro num campo escondido (UF de presidente).
    .superRefine((values, ctx) => {
        if (values.cargo !== 'presidente' && values.uf.length !== 2) {
            ctx.addIssue({
                code: 'custom',
                path: ['uf'],
                message: 'Selecione a UF.',
            });
        }

        if (
            CARGOS_MUNICIPAIS.includes(values.cargo) &&
            values.municipio.trim() === ''
        ) {
            ctx.addIssue({
                code: 'custom',
                path: ['municipio'],
                message: 'Informe o município.',
            });
        }
    });
type Values = z.infer<typeof schema>;

export default function CreatePoll({ elections }: Props) {
    const {
        control,
        register,
        setValue,
        setError,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            eleicao_id: elections[0] ? String(elections[0].id) : '',
            cargo: '',
            uf: '',
            municipio: '',
            turno: '1',
            cenario: 'estimulado_1t',
            instituto: '',
            publicada_em: '',
            coleta_inicio_em: '',
            coleta_fim_em: '',
            tamanho_amostra: '',
            margem_erro: '',
            metodologia: '',
            abrangencia: '',
            tipo: '',
            fonte_url: '',
            provider: '',
            confidence_score: '90',
            observacao: '',
        },
    });
    const [candidateRows, setCandidateRows] = useState<CandidateRowInput[]>([
        emptyCandidateRow(),
    ]);
    const [candidatesError, setCandidatesError] = useState('');

    const eleicaoId = useWatch({ control, name: 'eleicao_id' });
    const cargo = useWatch({ control, name: 'cargo' });
    const uf = useWatch({ control, name: 'uf' });
    const municipio = useWatch({ control, name: 'municipio' });
    const turno = useWatch({ control, name: 'turno' });
    const cenario = useWatch({ control, name: 'cenario' });
    const isMunicipal = CARGOS_MUNICIPAIS.includes(cargo);
    const electionOptions = useMemo(
        () =>
            elections.map((election) => ({
                value: String(election.id),
                label: `${election.name} (${election.year})`,
            })),
        [elections],
    );
    const { options: candidateOptions, loading: loadingCandidates } =
        usePollCandidateOptions({
            eleicaoId: eleicaoId ? Number(eleicaoId) : null,
            cargo,
            uf: cargo === 'presidente' ? '' : uf,
            municipio: isMunicipal ? municipio : '',
        });

    const submit = (values: Values) => {
        const cleanedRows = candidateRows.filter(
            (row) => row.nome.trim() !== '',
        );

        if (cleanedRows.length === 0) {
            setCandidatesError('Adicione ao menos um candidato.');

            return;
        }

        setCandidatesError('');

        router.post(
            '/admin/pesquisas-eleitorais',
            {
                eleicao_id: Number(values.eleicao_id),
                cargo: values.cargo,
                uf: values.cargo === 'presidente' ? 'BR' : values.uf,
                municipio: isMunicipal ? values.municipio || null : null,
                turno: Number(values.turno),
                cenario: values.cenario,
                instituto: values.instituto,
                publicada_em: values.publicada_em,
                coleta_inicio_em: values.coleta_inicio_em || null,
                coleta_fim_em: values.coleta_fim_em || null,
                tamanho_amostra: values.tamanho_amostra
                    ? Number(values.tamanho_amostra)
                    : null,
                margem_erro: values.margem_erro
                    ? Number(values.margem_erro)
                    : null,
                metodologia: values.metodologia || null,
                abrangencia: values.abrangencia || null,
                tipo: values.tipo || null,
                fonte_url: values.fonte_url,
                provider: values.provider,
                confidence_score: Number(values.confidence_score),
                observacao: values.observacao || null,
                candidatos: cleanedRows.map((row) => ({
                    nome: row.nome.trim(),
                    partido: row.partido.trim() || null,
                    percentual: row.percentual,
                    candidato_politico_id: row.candidato_politico_id,
                })),
            },
            {
                onError: (serverErrors) => {
                    Object.entries(serverErrors).forEach(([key, message]) => {
                        if (key.startsWith('candidatos')) {
                            setCandidatesError(message);

                            return;
                        }

                        setError(key as keyof Values, { message });
                    });
                },
            },
        );
    };
    const field = (
        name: keyof Values,
        label: string,
        type = 'text',
        extra: Record<string, unknown> = {},
    ) => (
        <div className="space-y-1">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} type={type} {...extra} {...register(name)} />
            <FieldError message={errors[name]?.message} />
        </div>
    );

    return (
        <>
            <Head title="Nova pesquisa eleitoral" />
            <PageContainer>
                <PageHeader
                    title="Nova pesquisa manual"
                    description="Registre à mão uma pesquisa que o PollingData não cobre"
                />
                <form onSubmit={handleSubmit(submit)} className="space-y-6">
                    <Card className="gap-0 py-0">
                        <SurfaceHeader>
                            <SurfaceTitle>
                                Identificação da pesquisa
                            </SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-5 p-5">
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="eleicao_id">Eleição</Label>
                                    <AppSelect
                                        value={eleicaoId}
                                        onValueChange={(value) =>
                                            setValue('eleicao_id', value, {
                                                shouldValidate: true,
                                            })
                                        }
                                        options={electionOptions}
                                        placeholder="Selecione a eleição"
                                    />
                                    <FieldError
                                        message={errors.eleicao_id?.message}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="cargo">Cargo</Label>
                                    <AppSelect
                                        value={cargo}
                                        onValueChange={(value) =>
                                            setValue('cargo', value, {
                                                shouldValidate: true,
                                            })
                                        }
                                        options={CARGO_OPTIONS}
                                        placeholder="Selecione o cargo"
                                    />
                                    <FieldError
                                        message={errors.cargo?.message}
                                    />
                                </div>
                                {cargo !== 'presidente' && (
                                    <div className="space-y-1">
                                        <Label htmlFor="uf">UF</Label>
                                        <AppSelect
                                            value={uf}
                                            onValueChange={(value) =>
                                                setValue('uf', value, {
                                                    shouldValidate: true,
                                                })
                                            }
                                            options={UF_OPTIONS}
                                            placeholder="Selecione a UF"
                                        />
                                        <FieldError
                                            message={errors.uf?.message}
                                        />
                                    </div>
                                )}
                                {isMunicipal && field('municipio', 'Município')}
                                <div className="space-y-1">
                                    <Label htmlFor="turno">Turno</Label>
                                    <AppSelect
                                        value={turno}
                                        onValueChange={(value) =>
                                            setValue('turno', value)
                                        }
                                        options={TURNO_OPTIONS}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="cenario">Cenário</Label>
                                    <AppSelect
                                        value={cenario}
                                        onValueChange={(value) =>
                                            setValue('cenario', value)
                                        }
                                        options={CENARIO_OPTIONS}
                                    />
                                </div>
                                {field('instituto', 'Instituto')}
                                {field('publicada_em', 'Publicada em', 'date')}
                                {field(
                                    'coleta_inicio_em',
                                    'Coleta iniciada em (opcional)',
                                    'date',
                                )}
                                {field(
                                    'coleta_fim_em',
                                    'Coleta encerrada em (opcional)',
                                    'date',
                                )}
                                {field(
                                    'tamanho_amostra',
                                    'Tamanho da amostra (opcional)',
                                    'number',
                                )}
                                {field(
                                    'margem_erro',
                                    'Margem de erro % (opcional)',
                                    'number',
                                    { step: '0.1' },
                                )}
                                {field('metodologia', 'Metodologia (opcional)')}
                                {field('abrangencia', 'Abrangência (opcional)')}
                                {field('tipo', 'Tipo (opcional)')}
                            </div>
                            {field('fonte_url', 'URL da fonte pública', 'url')}
                        </div>
                    </Card>

                    <Card className="gap-0 py-0">
                        <SurfaceHeader help="Descreva de onde os números vieram e o quanto confia neles.">
                            <SurfaceTitle>Proveniência</SurfaceTitle>
                        </SurfaceHeader>
                        <div className="space-y-5 p-5">
                            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                Uma confiança menor do que a de uma fonte já
                                registrada para esta pesquisa não sobrescreve o
                                resultado atual — fica só como auditoria.
                            </p>
                            <div className="grid gap-5 md:grid-cols-2">
                                {field(
                                    'provider',
                                    'Fonte (ex.: "AtlasIntel — PDF oficial")',
                                )}
                                {field(
                                    'confidence_score',
                                    'Confiança (1-100)',
                                    'number',
                                    { min: 1, max: 100 },
                                )}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="observacao">
                                    Observação (opcional)
                                </Label>
                                <Textarea
                                    id="observacao"
                                    {...register('observacao')}
                                />
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
                                        setCandidateRows([
                                            ...candidateRows,
                                            emptyCandidateRow(),
                                        ])
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
                                rows={candidateRows}
                                onChange={setCandidateRows}
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
                        <Button disabled={isSubmitting}>
                            Registrar pesquisa
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

CreatePoll.layout = {
    breadcrumbs: [
        { title: 'Administração', href: '/dashboard' },
        {
            title: 'Pesquisas eleitorais',
            href: '/admin/pesquisas-eleitorais',
        },
        { title: 'Nova pesquisa', href: '/admin/pesquisas-eleitorais/nova' },
    ],
};
