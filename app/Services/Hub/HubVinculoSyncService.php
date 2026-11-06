<?php

namespace App\Services\Hub;

use App\Models\Entity;
use App\Models\EntityMember;
use App\Models\Office;
use App\Models\OfficeMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica no GEP os vínculos que o Hub declara para uma pessoa.
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
 * do GEP, e o Hub não é dono dele.
 */
class HubVinculoSyncService
{
    public function __construct(private readonly HubEstruturaSyncService $estrutura) {}

    /**
     * Retrato completo: aplica todos os vínculos recebidos, desativa os que
     * sumiram e reprojeta o contexto legado.
     *
     * @param  array<int, array<string, mixed>>  $vinculos
     */
    public function aplicar(User $user, array $vinculos): void
    {
        foreach ($vinculos as $vinculo) {
            $this->garantirEstrutura($user, $vinculo);
        }

        DB::transaction(function () use ($user, $vinculos): void {
            $entidadesVistas = [];
            $gabinetesVistos = [];
            $ignorados = 0;

            foreach ($vinculos as $vinculo) {
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
        $this->garantirEstrutura($user, $vinculo);

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
     * Entity ou unidade do vínculo que ainda não está espelhada é criada
     * sob demanda pela API do Hub (respeitando a habilitação do GEP na
     * entidade e os tipos com equivalente) — a fila não garante que o
     * `*.criada` chegue antes do vínculo. Fica fora da transação dos vínculos
     * porque envolve HTTP. Se não der (Hub fora do ar, GEP não habilitado,
     * tipo sem equivalente), `escrever()` ignora o vínculo como antes.
     *
     * @param  array<string, mixed>  $vinculo
     */
    private function garantirEstrutura(User $user, array $vinculo): void
    {
        $hubEntidadeId = $this->texto($vinculo['entidade_id'] ?? null);
        $hubUnidadeId = $this->texto($vinculo['unidade_id'] ?? null);

        if ($hubEntidadeId === null) {
            return;
        }

        try {
            if ($hubUnidadeId !== null) {
                $this->estrutura->resolverUnidade($hubEntidadeId, $hubUnidadeId);
            } else {
                $this->estrutura->resolverEntidade($hubEntidadeId);
            }
        } catch (Throwable $e) {
            // Falhar aqui não pode derrubar o login nem os demais vínculos:
            // o vínculo só fica sem espelho, como se a estrutura não existisse.
            if (! $e instanceof HubIndisponivelException) {
                report($e);
            }

            Log::warning('Estrutura do vínculo não resolvida no Govnex Hub.', [
                'hub_entidade_id' => $hubEntidadeId,
                'hub_unidade_id' => $hubUnidadeId,
                'usuario_id' => $user->id,
                'erro' => $e->getMessage(),
            ]);
        }
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

        $entidade = Entity::query()->where('hub_entidade_id', $hubEntidadeId)->first();

        if ($entidade === null) {
            // Nem sob demanda deu para espelhar (Hub fora do ar, GEP não
            // habilitado na entidade, tipo sem equivalente). Ignorar o
            // vínculo é preferível a inventar uma entidade local.
            Log::warning('Vínculo do Hub ignorado: entidade não espelhada no GEP.', [
                'hub_entidade_id' => $hubEntidadeId,
                'usuario_id' => $user->id,
            ]);

            return null;
        }

        $gabinete = null;

        if ($hubUnidadeId !== null) {
            $gabinete = Office::withoutGlobalScopes()
                ->where('entidade_id', $entidade->id)
                ->where('hub_unidade_id', $hubUnidadeId)
                ->first();

            if ($gabinete === null) {
                Log::warning('Vínculo do Hub ignorado: gabinete não espelhado no GEP.', [
                    'hub_unidade_id' => $hubUnidadeId,
                    'usuario_id' => $user->id,
                ]);

                return null;
            }
        }

        // O `papel` que o Hub ainda envia é ignorado: o vínculo só diz que a
        // pessoa está vinculada. O que ela faz no gabinete é o cargo local.
        $ativo = $this->booleano($vinculo['ativo'] ?? true);

        $this->escreverMembro(
            EntityMember::query()->firstOrNew([
                'entidade_id' => $entidade->id,
                'usuario_id' => $user->id,
            ]),
            $ativo,
        );

        if ($gabinete !== null) {
            $this->escreverMembro(
                OfficeMember::query()->firstOrNew([
                    'gabinete_id' => $gabinete->id,
                    'usuario_id' => $user->id,
                ]),
                $ativo,
            );
        }

        return [$entidade->id, $gabinete?->id];
    }

    /**
     * Um vínculo desativado e reativado depois preserva o `ingressou_em`
     * original — é a data de entrada da pessoa naquele contexto, não a data do
     * último evento que chegou.
     *
     * `gabinete_membros.cargo` é local do GEP: o Hub nunca o envia, e este
     * método não pode escrevê-lo nem limpá-lo.
     */
    private function escreverMembro(EntityMember|OfficeMember $membro, bool $ativo): void
    {
        // Reativar um vínculo cujo cargo de vereador/chefe já foi passado a
        // outra pessoa ativa quebraria a regra de 1 por gabinete: o vínculo
        // volta, mas sem esse cargo (única exceção a "o sync nunca mexe no
        // cargo").
        if ($membro instanceof OfficeMember && $ativo && ! $membro->ativo && $membro->exists
            && $membro->cargo?->leadsOffice() === true
            && OfficeMember::query()
                ->where('gabinete_id', $membro->gabinete_id)
                ->whereKeyNot($membro->getKey())
                ->where('ativo', true)
                ->where('cargo', $membro->cargo->value)
                ->exists()) {
            Log::warning('Vínculo reativado pelo Hub sem o cargo de liderança, já ocupado por outra pessoa.', [
                'gabinete_id' => $membro->gabinete_id,
                'usuario_id' => $membro->usuario_id,
                'cargo' => $membro->cargo->value,
            ]);
            $membro->forceFill(['cargo' => null]);
        }

        $membro->forceFill([
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
        OfficeMember::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->when($gabinetesVistos !== [], fn ($q) => $q->whereNotIn('gabinete_id', $gabinetesVistos))
            ->update(['ativo' => false, 'desativado_em' => now()]);

        EntityMember::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->when($entidadesVistas !== [], fn ($q) => $q->whereNotIn('entidade_id', $entidadesVistas))
            ->update(['ativo' => false, 'desativado_em' => now()]);
    }

    /**
     * Projeta o vínculo principal nos campos herdados do modelo de "um usuário
     * por gabinete".
     *
     * `users.gabinete_id` continua sendo o que Policies, filas,
     * comandos e notificações leem fora de uma requisição com contexto
     * resolvido (ver `ResolveEntityContext`). Enquanto essa dívida existir,
     * eles precisam refletir algum vínculo real — e o critério é o mesmo da
     * entrada pós-login: o vínculo ativo mais antigo.
     *
     * Root fica de fora: é conta local de emergência (decisão #6) e não pertence
     * a gabinete nenhum.
     *
     * A gravação é silenciosa de propósito. `UserEntityMembershipObserver`
     * reescreveria os vínculos a partir de `role`/`gabinete_id` — o caminho
     * inverso deste — e, com o Hub como fonte da verdade, dois escritores para
     * a mesma linha só produziriam disputa. O observer continua valendo para as
     * contas que ainda nascem dentro do GEP.
     */
    private function reprojetarContextoLegado(User $user): void
    {
        if ($user->isRoot()) {
            return;
        }

        $principal = OfficeMember::query()
            ->where('usuario_id', $user->id)
            ->where('ativo', true)
            ->orderBy('ingressou_em')
            ->orderBy('id')
            ->first();

        // `users.role` não é mais projetado: fora a marca de root, ele é
        // inerte (o cargo local decide).
        $user->forceFill(['gabinete_id' => $principal?->gabinete_id])->saveQuietly();
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
