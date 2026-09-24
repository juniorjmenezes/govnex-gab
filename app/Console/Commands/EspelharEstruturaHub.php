<?php

namespace App\Console\Commands;

use App\Enums\EntidadeType;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Services\Hub\GovnexHubApiClient;
use App\Services\Hub\HubEstruturaSyncService;
use App\Services\Hub\HubIndisponivelException;
use App\Services\Hub\HubTipoMapper;
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
 *
 * Com `--atualizar`, também aplica o nome e a situação do Hub aos itens
 * ligados (`HubEstruturaSyncService`, o mesmo caminho do webhook). É a rede de
 * segurança para aviso perdido ou expirado e para divergências antigas; pede
 * ao Hub inclusive as suspensas. Depois de ligado, o casamento é pelo id do
 * Hub — o slug local nunca é alterado.
 *
 * Com `--criar`, cria no GAB a estrutura do Hub que ainda não tem espelho —
 * só entidade com o GAB habilitado e tipo com equivalente, e só unidade raiz
 * com tipo com equivalente —, pelo mesmo caminho do webhook
 * (`HubEstruturaSyncService::criarEntidade`/`criarUnidade`). É a rede de
 * segurança para `*.criada` perdido e para entidades em que o GAB foi
 * habilitado depois de criadas. Com `--dry-run`, só lista o que nasceria.
 */
class EspelharEstruturaHub extends Command
{
    protected $signature = 'hub:espelhar-estrutura
        {--dry-run : Mostra o que seria preenchido sem gravar}
        {--atualizar : Aplica nome e situação do Hub aos itens ligados}
        {--criar : Cria no GAB a estrutura habilitada no Hub que ainda não tem espelho}';

    protected $description = 'Preenche hub_entidade_id e hub_unidade_id casando a estrutura do Hub por slug';

    private bool $atualizar = false;

    private bool $criar = false;

    public function handle(GovnexHubApiClient $hub, HubEstruturaSyncService $estrutura): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->atualizar = (bool) $this->option('atualizar');
        $this->criar = (bool) $this->option('criar');
        $r = [
            'casadas' => 0, 'ja_preenchidas' => 0, 'sem_local' => [], 'conflitos' => [],
            'unidades_casadas' => 0, 'unidades_ja_preenchidas' => 0, 'unidades_sem_local' => [],
            'atualizacoes' => [], 'criacoes' => [],
        ];
        $entidadesVistas = [];
        $gabinetesVistos = [];

        try {
            foreach ($hub->contas() as $conta) {
                foreach ($hub->entidadesDaConta($conta['id'], ! $this->atualizar) as $eh) {
                    $this->espelharEntidade($hub, $estrutura, $eh, $dryRun, $r, $entidadesVistas, $gabinetesVistos);
                }
            }
        } catch (RuntimeException|HubIndisponivelException $e) {
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
        if ($this->atualizar) {
            $this->line('Nome/situação '.($dryRun ? 'divergentes do Hub' : 'atualizados pelo Hub').': '.count($r['atualizacoes']));

            foreach ($r['atualizacoes'] as $atualizacao) {
                $this->line("  {$atualizacao}");
            }
        }

        if ($this->criar) {
            $this->line(($dryRun ? 'Seriam criados no GAB' : 'Criados no GAB').': '.count($r['criacoes']));

            foreach ($r['criacoes'] as $criacao) {
                $this->line("  {$criacao}");
            }
        }

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
    private function espelharEntidade(GovnexHubApiClient $hub, HubEstruturaSyncService $estrutura, array $eh, bool $dryRun, array &$r, array &$entidadesVistas, array &$gabinetesVistos): void
    {
        $hubId = (string) $eh['id'];
        $slug = (string) ($eh['slug'] ?? '');
        // Já ligada casa pelo id (o slug do Hub muda a cada renomeação);
        // o slug só serve para ligar pela primeira vez.
        $entidade = Entidade::query()->where('hub_entidade_id', $hubId)->first()
            ?? ($slug === '' ? null : Entidade::query()->where('slug', $slug)->first());

        if ($entidade === null) {
            if ($this->criar) {
                $entidade = $this->criarEntidadeFaltante($hub, $estrutura, $eh, $dryRun, $r);
            } else {
                $r['sem_local'][] = $slug !== '' ? $slug : "#{$hubId}";
            }

            if ($entidade !== null) {
                $entidadesVistas[] = $entidade->id;
                $this->espelharUnidades($hub, $estrutura, $entidade, $hubId, $slug, $dryRun, $r, $gabinetesVistos);
            }

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

        if ($this->atualizar) {
            $this->atualizarEntidade($estrutura, $entidade, $eh, $dryRun, $r);
        }

        $this->espelharUnidades($hub, $estrutura, $entidade, $hubId, $slug, $dryRun, $r, $gabinetesVistos);
    }

    /**
     * Entidade do Hub sem espelho, com `--criar`: consulta a entidade (para
     * saber se o GAB está habilitado nela, fuso e município resolvidos) e a
     * cria pelo mesmo caminho do webhook. Em `--dry-run` só relata — e relata
     * também as unidades que nasceriam junto.
     *
     * @param  array<string, mixed>  $eh
     * @param  array<string, mixed>  $r
     */
    private function criarEntidadeFaltante(GovnexHubApiClient $hub, HubEstruturaSyncService $estrutura, array $eh, bool $dryRun, array &$r): ?Entidade
    {
        $hubId = (string) $eh['id'];
        $rotulo = (string) ($eh['slug'] ?? '') !== '' ? (string) $eh['slug'] : "#{$hubId}";
        $detalhe = $hub->entidade($hubId);

        if ($detalhe === null) {
            $r['sem_local'][] = "{$rotulo} (desconhecida pelo Hub)";

            return null;
        }

        $detalhe = ['id' => $hubId] + $detalhe;
        $motivo = $estrutura->motivoParaNaoCriarEntidade($detalhe);

        if ($motivo !== null) {
            $r['sem_local'][] = "{$rotulo} ({$motivo})";

            return null;
        }

        $r['criacoes'][] = "Entidade '{$rotulo}'";

        if ($dryRun) {
            /** @var EntidadeType $tipo */
            $tipo = app(HubTipoMapper::class)->entidadeType($estrutura->tipoDaEntidadeNoHub($detalhe));

            foreach ($this->achatar($hub->unidadesDaEntidade($hubId)) as $uh) {
                $uRotulo = "{$rotulo}/".((string) ($uh['slug'] ?? '') !== '' ? $uh['slug'] : "#{$uh['id']}");
                $uMotivo = $estrutura->motivoParaNaoCriarUnidade($tipo, $uh);

                if ($uMotivo === null) {
                    $r['criacoes'][] = "Gabinete '{$uRotulo}'";
                } else {
                    $r['unidades_sem_local'][] = "{$uRotulo} ({$uMotivo})";
                }
            }

            return null;
        }

        $estrutura->criarEntidade($detalhe);

        return Entidade::query()->where('hub_entidade_id', $hubId)->first();
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<int, int>  $gabinetesVistos
     */
    private function espelharUnidades(GovnexHubApiClient $hub, HubEstruturaSyncService $estrutura, Entidade $entidade, string $hubId, string $slug, bool $dryRun, array &$r, array &$gabinetesVistos): void
    {
        foreach ($this->achatar($hub->unidadesDaEntidade($hubId, ! $this->atualizar)) as $uh) {
            $uId = (string) $uh['id'];
            $uSlug = (string) ($uh['slug'] ?? '');
            $gabinete = Gabinete::withoutGlobalScopes()->where('hub_unidade_id', $uId)->first()
                ?? ($uSlug === '' ? null : Gabinete::withoutGlobalScopes()
                    ->where('entidade_id', $entidade->id)->where('slug', $uSlug)->first());

            if ($gabinete === null) {
                $uRotulo = "{$slug}/".($uSlug !== '' ? $uSlug : "#{$uId}");

                if ($this->criar) {
                    $this->criarUnidadeFaltante($estrutura, $entidade, $hubId, $uh, $uRotulo, $dryRun, $r, $gabinetesVistos);
                } else {
                    $r['unidades_sem_local'][] = $uRotulo;
                }

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

            if ($this->atualizar) {
                $this->atualizarGabinete($estrutura, $gabinete, $uh, $dryRun, $r);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $uh
     * @param  array<string, mixed>  $r
     * @param  array<int, int>  $gabinetesVistos
     */
    private function criarUnidadeFaltante(HubEstruturaSyncService $estrutura, Entidade $entidade, string $hubEntidadeId, array $uh, string $rotulo, bool $dryRun, array &$r, array &$gabinetesVistos): void
    {
        $motivo = $estrutura->motivoParaNaoCriarUnidade($entidade->tipo, $uh);

        if ($motivo === null && $entidade->tipo === EntidadeType::IndependentOffice
            && $entidade->gabinetes()->withoutGlobalScopes()->exists()) {
            $motivo = 'entidade_nao_aceita';
        }

        if ($motivo !== null) {
            $r['unidades_sem_local'][] = "{$rotulo} ({$motivo})";

            return;
        }

        if (! $dryRun) {
            $resultado = $estrutura->criarUnidade(['entidade_id' => $hubEntidadeId] + $uh);

            if (($resultado['acao'] ?? null) !== 'unidade_criada') {
                $r['unidades_sem_local'][] = "{$rotulo} (".($resultado['motivo'] ?? $resultado['acao'] ?? 'ignorada').')';

                return;
            }

            $gabinetesVistos[] = (int) $resultado['gabinete_id'];
        }

        $r['criacoes'][] = "Gabinete '{$rotulo}'";
    }

    /**
     * @param  array<string, mixed>  $eh
     * @param  array<string, mixed>  $r
     */
    private function atualizarEntidade(HubEstruturaSyncService $estrutura, Entidade $entidade, array $eh, bool $dryRun, array &$r): void
    {
        $mudancas = $estrutura->mudancasDaEntidade($entidade, $eh);

        if ($mudancas === null) {
            return;
        }

        $resumo = $this->resumo($mudancas);

        if ($resumo !== null) {
            $r['atualizacoes'][] = "Entidade '{$entidade->slug}': {$resumo}";
        }

        if (! $dryRun && $mudancas !== []) {
            $entidade->forceFill($mudancas)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $uh
     * @param  array<string, mixed>  $r
     */
    private function atualizarGabinete(HubEstruturaSyncService $estrutura, Gabinete $gabinete, array $uh, bool $dryRun, array &$r): void
    {
        $mudancas = $estrutura->mudancasDaUnidade($gabinete, $uh);

        if ($mudancas === null) {
            return;
        }

        $resumo = $this->resumo($mudancas);

        if ($resumo !== null) {
            $r['atualizacoes'][] = "Gabinete '{$gabinete->slug}': {$resumo}";
        }

        if (! $dryRun && $mudancas !== []) {
            $gabinete->forceFill($mudancas)->save();
        }
    }

    /** @param  array<string, mixed>  $mudancas */
    private function resumo(array $mudancas): ?string
    {
        $partes = [];

        if (isset($mudancas['nome'])) {
            $partes[] = "nome → \"{$mudancas['nome']}\"";
        }

        if (isset($mudancas['status'])) {
            $partes[] = 'situação → '.$mudancas['status']->value;
        }

        return $partes === [] ? null : implode(', ', $partes);
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
