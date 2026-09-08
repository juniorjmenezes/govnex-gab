<?php

namespace App\Services\Politics\Polls;

use App\Models\PesquisaEleitoral;
use App\Models\PesquisaFonte;
use App\Models\ResultadoPesquisaEleitoral;

/**
 * Orquestra os providers (ver {@see PesquisaResultProvider}) para uma
 * pesquisa e decide o que persistir. Duas regras centrais:
 *
 * 1. Toda tentativa de coleta — encontrou resultado ou não — vira um
 *    registro em pesquisa_fontes, para rastreabilidade.
 * 2. Uma fonte de confiança menor nunca sobrescreve um resultado já
 *    persistido por uma fonte de confiança maior ou igual à sua.
 */
class ResultResolver
{
    /** @param iterable<PesquisaResultProvider> $providers Em ordem de prioridade (o primeiro que suportar e encontrar algo vence) */
    public function __construct(
        private readonly iterable $providers = [],
    ) {}

    /**
     * Percorre os providers, em ordem, até um encontrar resultado para essa
     * pesquisa. Não persiste nada — só resolve qual resultado usar; quem
     * chama decide se aplica via {@see persist()}.
     */
    public function resolve(PesquisaEleitoral $pesquisa): ?ResultadoColeta
    {
        foreach ($this->providers as $provider) {
            if (! $provider->supports($pesquisa)) {
                continue;
            }

            $resultado = $provider->buscar($pesquisa);

            if ($resultado !== null) {
                return $resultado;
            }
        }

        return null;
    }

    /**
     * Registra a proveniência e, se a confiança permitir, grava os
     * percentuais em resultados_pesquisas_eleitorais e atualiza
     * confianca/origem_provider da pesquisa.
     */
    public function persist(PesquisaEleitoral $pesquisa, ResultadoColeta $resultado): PesquisaFonte
    {
        $fonte = $this->recordProvenance($pesquisa, $resultado);

        if ($this->shouldApply($pesquisa, $resultado)) {
            $this->writeResultados($pesquisa, $resultado);

            $pesquisa->forceFill([
                'confianca' => $resultado->confidenceScore,
                'origem_provider' => $resultado->provider,
            ])->save();
        }

        return $fonte;
    }

    /**
     * Só grava a auditoria em pesquisa_fontes, sem tocar em
     * resultados_pesquisas_eleitorais — usado quando quem chama já
     * persistiu o resultado por outro caminho (ex.: um fluxo em lote com
     * sua própria lógica de escrita/matching de candidato já testada).
     */
    public function recordProvenance(
        PesquisaEleitoral $pesquisa,
        ResultadoColeta $resultado,
        string $status = 'coletado',
    ): PesquisaFonte {
        return PesquisaFonte::query()->create([
            'pesquisa_eleitoral_id' => $pesquisa->id,
            'tipo' => $resultado->tipo,
            'provider' => $resultado->provider,
            'url' => $resultado->url,
            'status' => $status,
            'confidence_score' => $resultado->confidenceScore,
            'coletado_em' => now(),
            'hash_conteudo' => $resultado->hashConteudo,
            'metadata' => $resultado->metadata,
        ]);
    }

    private function shouldApply(PesquisaEleitoral $pesquisa, ResultadoColeta $resultado): bool
    {
        return $pesquisa->confianca === null || $resultado->confidenceScore >= $pesquisa->confianca;
    }

    private function writeResultados(PesquisaEleitoral $pesquisa, ResultadoColeta $resultado): void
    {
        $keptExternalIds = [];

        foreach ($resultado->candidatos as $candidato) {
            $keptExternalIds[] = $candidato['external_candidate_id'];

            ResultadoPesquisaEleitoral::query()->updateOrCreate(
                [
                    'pesquisa_eleitoral_id' => $pesquisa->id,
                    'external_candidate_id' => $candidato['external_candidate_id'],
                ],
                [
                    'candidato_politico_id' => $candidato['candidato_politico_id'] ?? null,
                    'candidato_nome' => $candidato['nome'],
                    'partido_sigla' => $candidato['partido'],
                    'percentual' => $candidato['percentual'],
                    'nao_valido' => $candidato['nao_valido'] ?? false,
                ],
            );
        }

        ResultadoPesquisaEleitoral::query()
            ->where('pesquisa_eleitoral_id', $pesquisa->id)
            ->when(
                $keptExternalIds !== [],
                fn ($query) => $query->whereNotIn('external_candidate_id', $keptExternalIds),
            )
            ->delete();
    }
}
