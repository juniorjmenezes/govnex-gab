<?php

namespace App\Services\Politics\Polls;

use App\Models\PesquisaEleitoral;

/**
 * Último recurso da cadeia de prioridade: lê uma entrada previamente
 * registrada à mão em pesquisa_fontes (tipo='manual', status='coletado',
 * com os candidatos em metadata.candidatos). Sempre "suporta" qualquer
 * pesquisa — é o fallback de revisão manual.
 *
 * Não existe ainda uma tela de administração para *criar* essas entradas —
 * este provider só define o contrato de leitura; a curadoria manual em si
 * (formulário, validação, etc.) fica para um incremento futuro.
 *
 * Nota: como busca sempre a fonte 'manual' mais recente, e o próprio
 * ResultResolver::persist() grava uma nova pesquisa_fontes ao aplicar o
 * resultado (sem metadata.candidatos), uma chamada repetida a resolve()
 * depois de já ter aplicado não reencontra a mesma entrada — é intencional
 * (semântica de "aplica uma vez"), não um bug de duplicação infinita.
 */
class ManualProvider implements PesquisaResultProvider
{
    public function supports(PesquisaEleitoral $pesquisa): bool
    {
        return true;
    }

    public function buscar(PesquisaEleitoral $pesquisa): ?ResultadoColeta
    {
        $fonte = $pesquisa->fontes()
            ->where('tipo', 'manual')
            ->where('status', 'coletado')
            ->latest('coletado_em')
            ->first();

        if (! $fonte) {
            return null;
        }

        $candidatos = $fonte->metadata['candidatos'] ?? null;

        if (! is_array($candidatos) || $candidatos === []) {
            return null;
        }

        $normalizados = [];

        foreach ($candidatos as $candidato) {
            if (
                ! is_array($candidato)
                || ! isset($candidato['external_candidate_id'], $candidato['nome'], $candidato['percentual'])
                || ! is_string($candidato['external_candidate_id'])
                || ! is_string($candidato['nome'])
                || ! is_numeric($candidato['percentual'])
                || (isset($candidato['candidato_politico_id']) && ! is_int($candidato['candidato_politico_id']))
                || (isset($candidato['partido']) && ! is_string($candidato['partido']))
            ) {
                continue;
            }

            $normalizados[] = [
                'external_candidate_id' => $candidato['external_candidate_id'],
                'candidato_politico_id' => $candidato['candidato_politico_id'] ?? null,
                'nome' => $candidato['nome'],
                'partido' => $candidato['partido'] ?? null,
                'percentual' => (float) $candidato['percentual'],
            ];
        }

        if ($normalizados === []) {
            return null;
        }

        return new ResultadoColeta(
            provider: 'manual',
            tipo: 'manual',
            confidenceScore: $fonte->confidence_score,
            candidatos: $normalizados,
            url: $fonte->url,
            hashConteudo: $fonte->hash_conteudo,
        );
    }
}
