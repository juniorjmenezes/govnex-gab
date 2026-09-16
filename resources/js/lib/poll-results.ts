import type { PoliticalPollResult } from '@/types';

/** Identifica a linha de votos em branco/nulo publicada por alguns
 * institutos como se fosse mais um "candidato" no resultado — deve sempre
 * aparecer por último na lista, independente do percentual. */
export function isInvalidVoteLabel(name: string): boolean {
    const normalized = name.toUpperCase().trim();

    return (
        normalized === 'NÃO VÁLIDO' ||
        normalized === 'BRANCO/NULO' ||
        normalized === 'BRANCOS E NULOS' ||
        normalized === 'BRANCOS/NULOS'
    );
}

/** Candidatos em ordem decrescente, com brancos/nulos sempre no fim. */
export function sortPollResults<T extends PoliticalPollResult>(
    results: readonly T[],
): T[] {
    return results.toSorted(
        (a, b) =>
            Number(isInvalidVoteLabel(a.name)) -
                Number(isInvalidVoteLabel(b.name)) ||
            b.percentage - a.percentage,
    );
}
