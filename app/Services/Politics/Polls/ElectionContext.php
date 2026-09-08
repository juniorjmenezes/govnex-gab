<?php

namespace App\Services\Politics\Polls;

/**
 * Identifica de forma determinística UMA corrida eleitoral no PollingData —
 * conhecida ANTES de qualquer chamada HTTP, nunca inferida do payload.
 * `sourceUrl()` monta a URL exata que o PollingData espera no parâmetro
 * `url` de `/api/polls/candidates` (confirmado: o parâmetro filtra de
 * verdade — Presidente/Governador/Senador, nacional ou por UF, sempre
 * devolvem apenas o subconjunto daquele contexto).
 */
final readonly class ElectionContext
{
    public function __construct(
        public int $year,
        public string $office,
        public string $uf,
        public int $round = 1,
        public string $slug = 't1',
    ) {}

    /**
     * Presidente não tem corrida nacional própria no PollingData — a visão
     * "todos os institutos combinados" fica em /presidente/br/t1_todas, não
     * /presidente/br/t1 (confirmado: essa URL devolve `noData: true`).
     */
    public static function presidenteNacional(int $year): self
    {
        return new self($year, 'presidente', 'BR', 1, 't1_todas');
    }

    public static function presidenteEstadual(int $year, string $uf): self
    {
        return new self($year, 'presidente', mb_strtoupper($uf), 1, 't1');
    }

    public static function governador(int $year, string $uf): self
    {
        return new self($year, 'governador', mb_strtoupper($uf), 1, 't1');
    }

    public static function senador(int $year, string $uf): self
    {
        return new self($year, 'senador', mb_strtoupper($uf), 1, 't1');
    }

    public function abrangencia(): string
    {
        return $this->office === 'presidente' && $this->uf === 'BR' ? 'nacional' : 'estadual';
    }

    public function sourceUrl(): string
    {
        return sprintf(
            'https://www.pollingdata.com.br/%d/%s/%s/%s',
            $this->year,
            $this->office,
            mb_strtolower($this->uf),
            $this->slug,
        );
    }

    /** Chave curta para logs, cache de fingerprint e agrupamento de resultados. */
    public function label(): string
    {
        return "{$this->office}/{$this->uf}/{$this->year}t{$this->round}";
    }
}
