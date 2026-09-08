import type { PollCurationCandidateOption } from '@/types';

export const UF_OPTIONS = [
    'AC',
    'AL',
    'AP',
    'AM',
    'BA',
    'CE',
    'DF',
    'ES',
    'GO',
    'MA',
    'MT',
    'MS',
    'MG',
    'PA',
    'PB',
    'PR',
    'PE',
    'PI',
    'RJ',
    'RN',
    'RS',
    'RO',
    'RR',
    'SC',
    'SP',
    'SE',
    'TO',
].map((uf) => ({ value: uf, label: uf }));

export const CARGO_OPTIONS = [
    { value: 'presidente', label: 'Presidente' },
    { value: 'governador', label: 'Governador' },
    { value: 'senador', label: 'Senador' },
    { value: 'prefeito', label: 'Prefeito' },
    { value: 'vereador', label: 'Vereador' },
];

export const CENARIO_OPTIONS = [
    { value: 'estimulado_1t', label: 'Estimulada — 1º turno' },
    { value: 'estimulado_2t', label: 'Estimulada — 2º turno' },
    { value: 'espontaneo_1t', label: 'Espontânea — 1º turno' },
    { value: 'espontaneo_2t', label: 'Espontânea — 2º turno' },
];

export const CENARIO_LABELS: Record<string, string> = Object.fromEntries(
    CENARIO_OPTIONS.map((option) => [option.value, option.label]),
);

export const TURNO_OPTIONS = [
    { value: '1', label: '1º turno' },
    { value: '2', label: '2º turno' },
];

export const CARGOS_MUNICIPAIS = ['prefeito', 'vereador'];

export type CandidateRowInput = {
    key: string;
    nome: string;
    partido: string;
    percentual: string;
    candidato_politico_id: number | null;
};

export function emptyCandidateRow(): CandidateRowInput {
    return {
        key:
            typeof crypto !== 'undefined' && 'randomUUID' in crypto
                ? crypto.randomUUID()
                : `row-${Date.now()}-${Math.random()}`,
        nome: '',
        partido: '',
        percentual: '',
        candidato_politico_id: null,
    };
}

export async function fetchCandidateOptions(params: {
    eleicaoId: number | null;
    cargo: string;
    uf: string;
    municipio: string;
}): Promise<PollCurationCandidateOption[]> {
    if (!params.eleicaoId || params.cargo === '') {
        return [];
    }

    const query = new URLSearchParams({
        eleicao_id: String(params.eleicaoId),
        cargo: params.cargo,
        uf: params.uf,
        municipio: params.municipio,
    });

    try {
        const response = await fetch(
            `/admin/pesquisas-eleitorais/candidatos?${query.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            return [];
        }

        return (await response.json()) as PollCurationCandidateOption[];
    } catch {
        return [];
    }
}
