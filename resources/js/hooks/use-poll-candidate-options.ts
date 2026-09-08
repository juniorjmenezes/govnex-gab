import { useEffect, useState } from 'react';
import { fetchCandidateOptions } from '@/lib/poll-curation';
import type { PollCurationCandidateOption } from '@/types';

/**
 * Busca candidatos elegíveis (eleição+cargo+UF/município) para vincular a
 * uma linha de resultado manual — refaz a busca sempre que os filtros
 * mudam, com um pequeno debounce para não disparar uma chamada a cada
 * tecla digitada no município.
 */
export function usePollCandidateOptions(params: {
    eleicaoId: number | null;
    cargo: string;
    uf: string;
    municipio: string;
}): { options: PollCurationCandidateOption[]; loading: boolean } {
    const { eleicaoId, cargo, uf, municipio } = params;
    const enabled = Boolean(eleicaoId) && cargo !== '';
    const [options, setOptions] = useState<PollCurationCandidateOption[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        const timeout = setTimeout(() => {
            fetchCandidateOptions({ eleicaoId, cargo, uf, municipio })
                .then((result) => {
                    if (!cancelled) {
                        setOptions(result);
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setLoading(false);
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timeout);
        };
    }, [enabled, eleicaoId, cargo, uf, municipio]);

    return { options: enabled ? options : [], loading: enabled && loading };
}
