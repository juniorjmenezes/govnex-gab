<?php

namespace App\Services\Politics\Polls;

/**
 * Resultado normalizado que um {@see PesquisaResultProvider} devolve para
 * uma pesquisa — sempre o conjunto completo de candidatos de um único
 * cenário (nunca misture cenários diferentes num só ResultadoColeta).
 *
 * Nunca preencha `percentual` inventando ou inferindo um valor ausente: se a
 * fonte não informou o percentual de um candidato, simplesmente não o
 * inclua em `candidatos`.
 */
final readonly class ResultadoColeta
{
    /**
     * @param  'oficial'|'pdf_oficial'|'portal'|'pollingdata'|'manual'  $tipo
     * @param  list<array{external_candidate_id: string, candidato_politico_id: ?int, nome: string, partido: ?string, percentual: float, nao_valido?: bool}>  $candidatos  candidato_politico_id já resolvido pelo provider (cada um conhece melhor seu próprio contexto de matching)
     * @param  array<string, mixed>|null  $metadata  Payload adicional para auditoria (ex.: resposta bruta da fonte)
     */
    public function __construct(
        public string $provider,
        public string $tipo,
        public int $confidenceScore,
        public array $candidatos,
        public ?string $url = null,
        public ?string $hashConteudo = null,
        public ?array $metadata = null,
    ) {}
}
