<?php

namespace App\Services\Hub;

use App\Enums\AccessRole;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica no GAB os vínculos que o Hub declara para uma pessoa.
 *
 * É a peça compartilhada pelos dois caminhos de provisionamento (decisão #3):
 * o login chama `aplicar()` com a lista inteira que a API de leitura devolveu,
 * e o webhook chama `aplicarVinculo()` com o único vínculo que mudou. As duas
 * entradas terminam no mesmo lugar, e é isso que impede login e webhook de
 * produzirem estados diferentes para a mesma pessoa.
 *
 * O que chega é retrato, não diferença: o bloco recebido é escrito por cima do
 * que existe. Por isso `aplicar()` pode desativar o que não veio — quem manda a
 * lista completa está afirmando que ela é completa. `aplicarVinculo()` nunca
 * desativa nada além do próprio vínculo, porque um evento isolado não afirma
 * nada sobre os outros.
 *
 * Nada aqui apaga linha: vínculo que acabou vira `ativo = false` com
 * `desativado_em`. O histórico de quem respondeu pelo quê é dado de auditoria
 * do GAB, e o Hub não é dono dele.
 */
class HubVinculoSyncService
{
    public function __construct(private readonly HubPapelMapper $papeis) {}

    /**
     * Retrato completo: aplica todos os vínculos recebidos, desativa os que
     * sumiram e reprojeta o contexto legado.
     *
     * @param  array<int, array<string, mixed>>  $vinculos
     */
    public function aplicar(User $user, array $vinculos): void
    {
        DB::transaction(function () use ($user, $vinculos): void {
            $entidadesVistas = [];
            $gabinetesVistos = [];
            $ignorados = 0;

            foreach ($vinculos as $vinculo) {
                // Root não é vínculo (é conta local): o item é descartado sem
                // contar como "não mapeado", para não travar a desativação
                // dos ausentes.
                if ($this->ehVinculoRoot($user, $vinculo)) {
                    continue;
                }

                $aplicado = $this->escrever($user, $vinculo);

                if ($aplicado === null) {
                    $ignorados++;

                    continue;
                }

                [$entidadeId, $gabineteId] = $aplicado;
                $entidadesVistas[] = $entidadeId;

                if ($gabineteId !== null) {
                    $gabinetesVistos[] = $gabineteId;
                }
            }

            // Vínculo que não deu para mapear (estrutura ainda não espelhada)
            // impede concluir que os locais "sumiram": o retrato não é
            // confiável. Melhor manter o acesso como está do que derrubá-lo.
            if ($ignorados > 0) {
                Log::warning('Sincronização do Hub sem desativar ausentes: há vínculos não mapeados.', [
                    'usuario_id' => $user->id,
                    'ignorados' => $ignorados,
                ]);
            } else {
                $this->desativarAusentes($user, $entidadesVistas, $gabinetesVistos);
            }

            $this->reprojetarContextoLegado($user);
        });
    }

    /**
     * Um único vínculo, do jeito que o webhook o entrega.
     *
     * @param  array<string, mixed>  $vinculo
     */
    public function aplicarVinculo(User $user, array $vinculo): void
    {
        if ($this->ehVinculoRoot($user, $vinculo)) {
            return;
        }

        DB::transaction(function () use ($user, $vinculo): void {
            $this->escrever($user, $vinculo);
            $this->reprojetarContextoLegado($user);
        });
    }

    /** Desliga tudo: usado por `pessoa.desligada`. */
    public function desativarTodos(User $user): void
    {
        $this->aplicar($user, []);
    }

    /**
     * @param  array<string, mixed>  $vinculo
     * @return array{0: int, 1: int|null}|null entidade e gabinete atingidos
     */
    private function escrever(User $user, array $vinculo): ?array
    {
        $hubEntidadeId = $this->texto($vinculo['entidade_id'] ?? null);
        $hubUnidadeId = $this->texto($vinculo['unidade_id'] ?? null);

        if ($hubEntidadeId === null) {
            return null;
        }

        $entidade = Entidade::query()->where('hub_entidade_id', $hubEntidadeId)->first();

        if ($entidade === null) {
            // Estrutura ainda não espelhada aqui. Não é erro de execução: a
            // entidade pode existir no Hub e ainda não ter sido criada no GAB.
            // Ignorar o vínculo é preferível a inventar uma entidade local.
            Log::warning('Vínculo do Hub ignorado: entidade não espelhada no GAB.', [
                'hub_entidade_id' => $hubEntidadeId,
                'usuario_id' => $user->id,
            ]);

            return null;
        }

        $gabinete = null;

        if ($hubUnidadeId !== null) {
            $gabinete = Gabinete::withoutGlobalScopes()
                ->where('entidade_id', $entidade->id)
                ->where('hub_unidade_id', $hubUnidadeId)
                ->first();

            if ($gabinete === null) {
                Log::warning('Vínculo do Hub ignorado: gabinete não espelhado no GAB.', [
                    'hub_unidade_id' => $hubUnidadeId,
                    'usuario_id' => $user->id,
                ]);

                return null;
            }
        }

        $ativo = $this->booleano($vinculo['ativo'] ?? true);
        $papel = $this->texto($vinculo['papel'] ?? null);
        $papelDeAcesso = $this->papeis->accessRole($papel);

        $this->escreverMembro(
            EntidadeMembro::query()->firstOrNew([
                'entidade_id' => $entidade->id,
                'usuario_id' => $user->id,
            ]),
            $gabinete !== null
                ? $this->papeis->entidadeRoleDeUnidade($papelDeAcesso, $entidade)
                : $papelDeAcesso,
            $ativo,
        );

        if ($gabinete !== null) {
            $this->escreverMembro(
                GabineteMembro::query()->firstOrNew([
                    'gabinete_id' => $gabinete->id,
                    'usuario_id' => $user->id,
                ]),
                $papelDeAcesso,
                $ativo,
            );
        }

        return [$entidade->id, $gabinete?->id];
    }

    /**
     * Um vínculo desativado e reativado depois preserva o `ingressou_em`
     * original — é a data de entrada da pessoa naquele contexto, não a data do
     * último evento que chegou.
     */
    private function escreverMembro(EntidadeMembro|GabineteMembro $membro, AccessRole $papel, bool $ativo): void
    {
        $membro->forceFill([
            'papel' => $papel,
            'ativo' => $ativo,
            'ingressou_em' => $membro->ingressou_em ?? now(),
            'desativado_em' => $ativo ? null : ($membro->desativado_em ?? now()),
        ])->save();
    }

    /**
     * @param  array<int, int>  $entidadesVistas
     * @param  array<int, int>  $gabinetesVistos
     */
    private function desativarAusentes(User $user, array $entidadesVistas, array $gabinetesVistos): void
    {
        GabineteMembro::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->when($gabinetesVistos !== [], fn ($q) => $q->whereNotIn('gabinete_id', $gabinetesVistos))
            ->update(['ativo' => false, 'desativado_em' => now()]);

        EntidadeMembro::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->when($entidadesVistas !== [], fn ($q) => $q->whereNotIn('entidade_id', $entidadesVistas))
            ->update(['ativo' => false, 'desativado_em' => now()]);
    }

    /**
     * Projeta o vínculo principal nos campos herdados do modelo de "um usuário
     * por gabinete".
     *
     * `users.role` e `users.gabinete_id` continuam sendo o que Policies, filas,
     * comandos e notificações leem fora de uma requisição com contexto
     * resolvido (ver `ResolveEntidadeContext`). Enquanto essa dívida existir,
     * eles precisam refletir algum vínculo real — e o critério é o mesmo da
     * entrada pós-login: o vínculo ativo mais antigo.
     *
     * Root fica de fora: é conta local de emergência (decisão #6) e não pertence
     * a gabinete nenhum.
     *
     * A gravação é silenciosa de propósito. `UserEntidadeMembershipObserver`
     * reescreveria os vínculos a partir de `role`/`gabinete_id` — o caminho
     * inverso deste — e, com o Hub como fonte da verdade, dois escritores para
     * a mesma linha só produziriam disputa. O observer continua valendo para as
     * contas que ainda nascem dentro do GAB.
     */
    private function reprojetarContextoLegado(User $user): void
    {
        if ($user->isRoot()) {
            return;
        }

        $principal = GabineteMembro::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->orderBy('ingressou_em')
            ->orderBy('id')
            ->first();

        $user->forceFill([
            'gabinete_id' => $principal?->gabinete_id,
            'role' => $principal !== null
                ? $this->papeis->userRole($principal->papel)
                : $user->role,
        ])->saveQuietly();
    }

    /** @param  array<string, mixed>  $vinculo */
    private function ehVinculoRoot(User $user, array $vinculo): bool
    {
        if (! $this->papeis->isRoot($this->texto($vinculo['papel'] ?? null))) {
            return false;
        }

        Log::warning('Vínculo do Hub ignorado: root é conta local do GAB, não papel de vínculo.', [
            'usuario_id' => $user->id,
            'hub_entidade_id' => $vinculo['entidade_id'] ?? null,
            'hub_unidade_id' => $vinculo['unidade_id'] ?? null,
        ]);

        return true;
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function booleano(mixed $valor): bool
    {
        return filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
