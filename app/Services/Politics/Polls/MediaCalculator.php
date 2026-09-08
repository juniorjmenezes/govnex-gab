<?php

namespace App\Services\Politics\Polls;

use App\Http\Controllers\PoliticalPanelController;
use App\Models\PesquisaEleitoral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Média própria por candidato, calculada a partir de resultados já
 * persistidos em resultados_pesquisas_eleitorais — reduz a dependência de
 * `GET /averages` do ElectioLab (ver item 8 do plano de refatoração de
 * pesquisas eleitorais). {@see PoliticalPanelController}
 * usa este cálculo quando ele cobre mais de uma pesquisa individual; caso
 * contrário (inclusive quando a fonte nunca publica detalhamento por
 * candidato, como as pesquisas de presidente do ElectioLab) o painel cai de
 * volta para `/averages` do ElectioLab, que tem histórico mais amplo.
 *
 * Fórmula (v1 — qualquer ajuste aqui exige atualizar esta doc e os testes):
 *
 * 1. Recência: só entram pesquisas publicadas nos últimos `lookbackDays`
 *    dias (padrão 60) — o resto do histórico não participa da média atual.
 * 2. Peso por pesquisa = sqrt(tamanho_amostra ?? 400): amostras maiores
 *    pesam mais, mas a raiz quadrada evita que uma pesquisa muito grande
 *    domine sozinha o resultado.
 * 3. Cenário: quem chama já deve filtrar as pesquisas para um único cenário
 *    (mesma eleição/UF/município/cargo/cenário) — este cálculo não mistura
 *    cenários diferentes por conta própria.
 * 4. Outliers: para cada candidato, calcula a mediana dos percentuais entre
 *    as pesquisas do grupo e descarta qualquer valor que se desvie mais de
 *    `outlierThreshold` pontos percentuais (padrão 15) dessa mediana antes
 *    de calcular a média ponderada final. Se isso descartar todo mundo (ex.:
 *    só 1-2 pesquisas muito divergentes), usa o conjunto original — melhor
 *    mostrar um número com ressalva do que não mostrar nada.
 *
 * Não considera ainda instituto/metodologia como peso — não há dados reais
 * suficientes hoje para calibrar isso de forma defensável; documentado como
 * lacuna conhecida, não esquecimento.
 */
final class MediaCalculator
{
    public function __construct(
        private readonly int $lookbackDays = 60,
        private readonly float $outlierThreshold = 15.0,
    ) {}

    /**
     * @param  Collection<int, PesquisaEleitoral>  $pesquisas  Já filtradas por eleição/UF/município/cargo/cenário; precisa vir com resultados carregado (with('resultados'))
     * @return list<array{candidato_politico_id: int|null, external_candidate_id: string, nome: string, partido: string|null, media: float, pesquisas_incluidas: int, amostra_total: int}> Ordenado por média decrescente
     */
    public function calcular(Collection $pesquisas): array
    {
        $corte = CarbonImmutable::now()->subDays($this->lookbackDays);
        $recentes = $pesquisas->filter(
            fn (PesquisaEleitoral $pesquisa): bool => CarbonImmutable::parse($pesquisa->publicada_em)->gte($corte),
        );

        if ($recentes->isEmpty()) {
            return [];
        }

        /** @var array<string, list<array{peso: float, percentual: float, nome: string, partido: string|null, candidato_politico_id: int|null, external_candidate_id: string, amostra: int}>> $porCandidato */
        $porCandidato = [];

        foreach ($recentes as $pesquisa) {
            $peso = sqrt((float) ($pesquisa->tamanho_amostra ?? 400));

            foreach ($pesquisa->resultados as $resultado) {
                $chave = $resultado->candidato_politico_id !== null
                    ? "id:{$resultado->candidato_politico_id}"
                    : 'nome:'.mb_strtolower($resultado->candidato_nome);

                $porCandidato[$chave][] = [
                    'peso' => $peso,
                    'percentual' => $resultado->percentual,
                    'nome' => $resultado->candidato_nome,
                    'partido' => $resultado->partido_sigla,
                    'candidato_politico_id' => $resultado->candidato_politico_id,
                    'external_candidate_id' => $resultado->external_candidate_id,
                    'amostra' => (int) ($pesquisa->tamanho_amostra ?? 0),
                ];
            }
        }

        $medias = [];

        foreach ($porCandidato as $entradas) {
            $percentuais = array_map(
                fn (array $entrada): float => $entrada['percentual'],
                $entradas,
            );
            $mediana = $this->mediana($percentuais);
            $semOutliers = array_values(array_filter(
                $entradas,
                fn (array $entrada): bool => abs($entrada['percentual'] - $mediana) <= $this->outlierThreshold,
            ));

            if ($semOutliers === []) {
                $semOutliers = $entradas;
            }

            $pesoTotal = array_sum(array_column($semOutliers, 'peso'));

            if ($pesoTotal <= 0) {
                continue;
            }

            $mediaPonderada = array_sum(array_map(
                fn (array $entrada): float => $entrada['peso'] * $entrada['percentual'],
                $semOutliers,
            )) / $pesoTotal;
            $primeira = $semOutliers[0];

            $medias[] = [
                'candidato_politico_id' => $primeira['candidato_politico_id'],
                'external_candidate_id' => $primeira['external_candidate_id'],
                'nome' => $primeira['nome'],
                'partido' => $primeira['partido'],
                'media' => round($mediaPonderada, 2),
                'pesquisas_incluidas' => count($semOutliers),
                'amostra_total' => (int) array_sum(array_column($semOutliers, 'amostra')),
            ];
        }

        usort($medias, fn (array $a, array $b): int => $b['media'] <=> $a['media']);

        return $medias;
    }

    /** @param list<float> $valores */
    private function mediana(array $valores): float
    {
        sort($valores);
        $count = count($valores);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($valores[$middle - 1] + $valores[$middle]) / 2
            : $valores[$middle];
    }
}
