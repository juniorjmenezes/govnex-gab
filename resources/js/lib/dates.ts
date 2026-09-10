const dayMonthYear = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const shortDateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

/**
 * Data-só (sem hora) em dd/MM/aaaa. O horário fixado ao meio-dia evita que
 * o fuso do navegador puxe a data para o dia anterior — o back-end manda
 * `2026-09-10` e sem isso um fuso negativo exibiria 09/09.
 */
export function formatDayMonthYear(date: string): string {
    return dayMonthYear.format(new Date(`${date.slice(0, 10)}T12:00:00`));
}

/** Data e hora curtas: 10/09/26 14:32. */
export function formatShortDateTime(value: string): string {
    return shortDateTime.format(new Date(value));
}
