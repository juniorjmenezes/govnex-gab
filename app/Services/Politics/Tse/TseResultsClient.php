<?php

namespace App\Services\Politics\Tse;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lê a apuração ao vivo publicada pelo TSE em resultados.tse.jus.br — um CDN
 * de arquivos JSON estáticos, sem API REST documentada, cuja estrutura e
 * códigos mudam a cada eleição. Nada aqui é fixado em "2026": o código do
 * turno é descoberto em tempo real no arquivo de configuração do próprio
 * TSE (`comum/config/ele-c.json`), então a mesma classe volta a funcionar
 * sozinha na eleição seguinte.
 *
 * Layout EA11 (configuração) e EA20 (resultado unificado por cargo), ambos
 * documentados em tse.jus.br/eleicoes/arquivos. Confirmado ao vivo em
 * 04/10/2026 contra a Eleição Geral 2026 (ver docs/REDESENHO_UI.md não — ver
 * histórico de conversa; sem fixture local porque o formato só existe
 * enquanto uma eleição está em curso).
 */
class TseResultsClient
{
    private const BASE_URL = 'https://resultados.tse.jus.br/oficial';

    /** Código TSE de cada cargo — estável entre eleições, ao contrário do
     * código do turno. */
    public const CARGO_CODES = [
        'presidente' => '1',
        'governador' => '3',
        'senador' => '5',
        'deputado_federal' => '6',
        'deputado_estadual' => '7',
    ];

    /** Cargos de abrangência nacional: consultados com UF "br". */
    private const NATIONAL_CARGOS = ['presidente'];

    /**
     * Arquivo de configuração geral: lista todos os pleitos e turnos
     * disponíveis para divulgação, com seus cargos. Não é específico de
     * ano/eleição no caminho — cacheado por tempo curto porque é pequeno e
     * pode mudar perto do pleito (ex.: 2º turno sendo liberado).
     *
     * @return array<string, mixed>|null
     */
    public function electionConfig(): ?array
    {
        return Cache::remember('tse.results.election-config', now()->addMinutes(10), function (): ?array {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get(self::BASE_URL.'/comum/config/ele-c.json');

            if ($response->failed()) {
                Log::warning('tse.results.election_config_failed', ['status' => $response->status()]);

                return null;
            }

            $payload = $response->json();

            return is_array($payload) ? $payload : null;
        });
    }

    /**
     * Acha, dentro do arquivo de configuração, o turno (não o pleito) que
     * inclui o cargo pedido e cai na data informada — ex.: para "governador"
     * na eleição de 04/10/2026, devolve o turno "Eleição Ordinária Estadual"
     * (cd 6259), não o pleito (cd 3220) nem o turno federal (cd 6257).
     *
     * @return array{turno_code: string, ciclo: string}|null
     */
    public function resolveRound(string $cargo, string $pleitoDate, int $round = 1): ?array
    {
        $cargoCode = self::CARGO_CODES[$cargo] ?? null;

        if ($cargoCode === null) {
            return null;
        }

        $config = $this->electionConfig();

        if ($config === null || ! isset($config['pl']) || ! is_array($config['pl'])) {
            return null;
        }

        // O pleito tem a data do 1º turno; o 2º turno é o mesmo pleito com
        // outro código de turno (`cdt2`), não outra entrada na configuração.
        $targetDate = Carbon::parse($pleitoDate)->format('d/m/Y');

        foreach ($config['pl'] as $pleito) {
            if (! is_array($pleito) || ($pleito['dt'] ?? null) !== $targetDate) {
                continue;
            }

            $ciclo = $pleito['c'] ?? null;

            if (! is_string($ciclo) || $ciclo === '') {
                continue;
            }

            foreach ($pleito['e'] ?? [] as $turno) {
                if (! is_array($turno) || ! $this->turnoHasCargo($turno, $cargoCode)) {
                    continue;
                }

                $turnoCode = $round === 2 ? ($turno['cdt2'] ?? null) : ($turno['cd'] ?? null);

                if (! is_string($turnoCode) || $turnoCode === '') {
                    continue;
                }

                return ['turno_code' => $turnoCode, 'ciclo' => $ciclo];
            }
        }

        return null;
    }

    private function turnoHasCargo(array $turno, string $cargoCode): bool
    {
        foreach ($turno['abr'] ?? [] as $abrangencia) {
            if (! is_array($abrangencia)) {
                continue;
            }

            foreach ($abrangencia['cp'] ?? [] as $cargo) {
                if (is_array($cargo) && ($cargo['cd'] ?? null) === $cargoCode) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resultado unificado (EA20) de um cargo, já normalizado: percentual de
     * seções apuradas e candidatos ordenados por votos. `null` quando o
     * arquivo ainda não existe (comum antes do fim da votação) ou o formato
     * mudou — a falha é sempre silenciosa aqui, quem chama decide o que
     * mostrar.
     *
     * @return array{section_percent: float, total_sections: int, counted_sections: int, candidates: array<int, array{number: string, name: string, ballot_name: string, party: string, coalition: string|null, votes: int, vote_percent: float, elected: bool}>}|null
     */
    public function results(string $cargo, string $uf, string $pleitoDate, int $round = 1, ?string $municipality = null): ?array
    {
        $cargoCode = self::CARGO_CODES[$cargo] ?? null;

        if ($cargoCode === null) {
            return null;
        }

        $turno = $this->resolveRound($cargo, $pleitoDate, $round);

        if ($turno === null) {
            return null;
        }

        $ufLower = mb_strtolower($uf);
        $scope = in_array($cargo, self::NATIONAL_CARGOS, true) ? 'br' : $ufLower;
        // Por município, a pasta é a da UF e o arquivo leva o código do
        // município (ex.: dados/ce/ce13692-c0001-…), como no feed oficial.
        $folder = $municipality !== null ? $ufLower : $scope;
        $file = $municipality !== null ? $ufLower.$municipality : $scope;
        $cargoPath = str_pad($cargoCode, 4, '0', STR_PAD_LEFT);
        // O diretório usa o código do turno cru (ex.: "6259"), mas o nome do
        // arquivo o quer com zero à esquerda até 6 dígitos (ex.: "e006259") —
        // confirmado contra o feed ao vivo em 04/10/2026.
        $roundFilePart = str_pad($turno['turno_code'], 6, '0', STR_PAD_LEFT);
        $url = sprintf(
            '%s/%s/%s/dados/%s/%s-c%s-e%s-u.json',
            self::BASE_URL,
            $turno['ciclo'],
            $turno['turno_code'],
            $folder,
            $file,
            $cargoPath,
            $roundFilePart,
        );

        $cacheKey = "tse.results.{$turno['turno_code']}.{$file}.{$cargoCode}";

        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($url, $cargoCode): ?array {
            $response = Http::acceptJson()->timeout(15)->get($url);

            if ($response->failed()) {
                return null;
            }

            $payload = $response->json();

            return is_array($payload) ? $this->normalize($payload, $cargoCode) : null;
        });
    }

    /** @return array<string, mixed>|null */
    private function normalize(array $payload, string $cargoCode): ?array
    {
        $sections = $payload['s'] ?? null;
        $cargos = $payload['carg'] ?? null;

        if (! is_array($sections) || ! is_array($cargos)) {
            return null;
        }

        $cargoBlock = collect($cargos)->first(fn (mixed $c): bool => is_array($c) && ($c['cd'] ?? null) === $cargoCode);

        if (! is_array($cargoBlock)) {
            return null;
        }

        $candidates = collect($cargoBlock['agr'] ?? [])
            ->filter(fn (mixed $agrupamento): bool => is_array($agrupamento))
            ->flatMap(fn (array $agrupamento): array => $this->candidatesFromAgrupamento($agrupamento))
            ->sortByDesc('votes')
            ->values()
            ->all();

        return [
            'section_percent' => $this->toFloat($sections['pst'] ?? '0'),
            'total_sections' => (int) ($sections['ts'] ?? 0),
            'counted_sections' => (int) ($sections['st'] ?? 0),
            'candidates' => $candidates,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candidatesFromAgrupamento(array $agrupamento): array
    {
        $coalition = is_string($agrupamento['com'] ?? null) ? $agrupamento['com'] : null;

        return collect($agrupamento['par'] ?? [])
            ->filter(fn (mixed $partido): bool => is_array($partido))
            ->flatMap(function (array $partido) use ($coalition): array {
                $party = is_string($partido['sg'] ?? null) ? $partido['sg'] : '';

                return collect($partido['cand'] ?? [])
                    ->filter(fn (mixed $candidato): bool => is_array($candidato))
                    ->map(fn (array $candidato): array => [
                        'number' => (string) ($candidato['n'] ?? ''),
                        'name' => (string) ($candidato['nm'] ?? ''),
                        'ballot_name' => (string) ($candidato['nmu'] ?? ($candidato['nm'] ?? '')),
                        'party' => $party,
                        'coalition' => $coalition,
                        'votes' => (int) ($candidato['vap'] ?? 0),
                        'vote_percent' => $this->toFloat($candidato['pvap'] ?? '0'),
                        'elected' => ($candidato['e'] ?? 'n') === 's',
                    ])
                    ->all();
            })
            ->all();
    }

    private function toFloat(mixed $value): float
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return 0.0;
        }

        return (float) str_replace(',', '.', (string) $value);
    }
}
