<?php

namespace App\Console\Commands;

use App\Models\Entidade;
use App\Models\Gabinete;
use App\Services\Hub\GovnexHubApiClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Preenche as colunas de ponte com a estrutura do Hub.
 *
 * Regra de mapeamento (a mesma que o Hub usou ao importar o GAB, em
 * `ImportadorDoGab`): a `Entidade` do Hub casa com a `Entidade` local pelo
 * `slug`, e a `Unidade` do Hub casa com o `Gabinete` local, dentro da entidade
 * já casada, pelo `slug`. Unidades do Hub sem gabinete equivalente (secretarias,
 * setores…) simplesmente não têm correspondência.
 *
 * Idempotente: só preenche `entidades.hub_entidade_id` e
 * `gabinetes.hub_unidade_id` quando estão nulos. Um id já preenchido nunca é
 * sobrescrito por outro diferente — vira conflito no relatório.
 */
class EspelharEstruturaHub extends Command
{
    protected $signature = 'hub:espelhar-estrutura {--dry-run : Mostra o que seria preenchido sem gravar}';

    protected $description = 'Preenche hub_entidade_id e hub_unidade_id casando a estrutura do Hub por slug';

    public function handle(GovnexHubApiClient $hub): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $r = [
            'casadas' => 0, 'ja_preenchidas' => 0, 'sem_local' => [], 'conflitos' => [],
            'unidades_casadas' => 0, 'unidades_ja_preenchidas' => 0, 'unidades_sem_local' => [],
        ];
        $entidadesVistas = [];
        $gabinetesVistos = [];

        try {
            foreach ($hub->contas() as $conta) {
                foreach ($hub->entidadesDaConta($conta['id']) as $eh) {
                    $this->espelharEntidade($hub, $eh, $dryRun, $r, $entidadesVistas, $gabinetesVistos);
                }
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $semHubEntidades = Entidade::query()->whereNotIn('id', $entidadesVistas)->pluck('slug')->all();
        $semHubGabinetes = Gabinete::withoutGlobalScopes()->whereNotIn('id', $gabinetesVistos)->pluck('slug')->all();

        $this->info(($dryRun ? '[dry-run] ' : '').'Espelhamento da estrutura do Hub');
        $this->line("Entidades casadas (preenchidas agora): {$r['casadas']}");
        $this->line("Entidades já preenchidas: {$r['ja_preenchidas']}");
        $this->line("Gabinetes casados (preenchidos agora): {$r['unidades_casadas']}");
        $this->line("Gabinetes já preenchidos: {$r['unidades_ja_preenchidas']}");
        $this->line('Entidades do Hub sem correspondência no GAB: '.count($r['sem_local']).$this->lista($r['sem_local']));
        $this->line('Unidades do Hub sem gabinete no GAB: '.count($r['unidades_sem_local']).$this->lista($r['unidades_sem_local']));
        $this->line('Entidades do GAB sem correspondência no Hub: '.count($semHubEntidades).$this->lista($semHubEntidades));
        $this->line('Gabinetes do GAB sem correspondência no Hub: '.count($semHubGabinetes).$this->lista($semHubGabinetes));
        $this->line('Conflitos: '.count($r['conflitos']));

        foreach ($r['conflitos'] as $conflito) {
            $this->warn($conflito);
        }

        return $r['conflitos'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $eh
     * @param  array<string, mixed>  $r
     * @param  array<int, int>  $entidadesVistas
     * @param  array<int, int>  $gabinetesVistos
     */
    private function espelharEntidade(GovnexHubApiClient $hub, array $eh, bool $dryRun, array &$r, array &$entidadesVistas, array &$gabinetesVistos): void
    {
        $hubId = (string) $eh['id'];
        $slug = (string) ($eh['slug'] ?? '');
        $entidade = $slug === '' ? null : Entidade::query()->where('slug', $slug)->first();

        if ($entidade === null) {
            $r['sem_local'][] = $slug !== '' ? $slug : "#{$hubId}";

            return;
        }

        if ($entidade->hub_entidade_id !== null && $entidade->hub_entidade_id !== $hubId) {
            $r['conflitos'][] = "Entidade '{$slug}': GAB já aponta para o Hub {$entidade->hub_entidade_id}, o Hub informou {$hubId}.";

            return;
        }

        if (in_array($entidade->id, $entidadesVistas, true)) {
            $r['conflitos'][] = "Entidade '{$slug}': mais de uma entidade do Hub com o mesmo slug (Hub {$hubId} ignorada).";

            return;
        }

        $entidadesVistas[] = $entidade->id;

        if ($entidade->hub_entidade_id === null) {
            $r['casadas']++;

            if (! $dryRun) {
                $entidade->forceFill(['hub_entidade_id' => $hubId])->save();
            }
        } else {
            $r['ja_preenchidas']++;
        }

        foreach ($this->achatar($hub->unidadesDaEntidade($hubId)) as $uh) {
            $uId = (string) $uh['id'];
            $uSlug = (string) ($uh['slug'] ?? '');
            $gabinete = $uSlug === '' ? null : Gabinete::withoutGlobalScopes()
                ->where('entidade_id', $entidade->id)->where('slug', $uSlug)->first();

            if ($gabinete === null) {
                $r['unidades_sem_local'][] = "{$slug}/".($uSlug !== '' ? $uSlug : "#{$uId}");

                continue;
            }

            if ($gabinete->hub_unidade_id !== null && $gabinete->hub_unidade_id !== $uId) {
                $r['conflitos'][] = "Gabinete '{$slug}/{$uSlug}': GAB já aponta para a unidade {$gabinete->hub_unidade_id}, o Hub informou {$uId}.";

                continue;
            }

            $gabinetesVistos[] = $gabinete->id;

            if ($gabinete->hub_unidade_id === null) {
                $r['unidades_casadas']++;

                if (! $dryRun) {
                    $gabinete->forceFill(['hub_unidade_id' => $uId])->save();
                }
            } else {
                $r['unidades_ja_preenchidas']++;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $arvore
     * @return array<int, array<string, mixed>>
     */
    private function achatar(array $arvore): array
    {
        $plano = [];

        foreach ($arvore as $no) {
            $plano[] = $no;

            if (is_array($no['unidades'] ?? null)) {
                array_push($plano, ...$this->achatar($no['unidades']));
            }
        }

        return $plano;
    }

    /** @param  array<int, string>  $itens */
    private function lista(array $itens): string
    {
        return $itens === [] ? '' : ' ('.implode(', ', $itens).')';
    }
}
