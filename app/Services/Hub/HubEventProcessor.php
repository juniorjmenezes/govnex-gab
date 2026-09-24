<?php

namespace App\Services\Hub;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Aplica um aviso do Hub na cópia local.
 *
 * O payload é retrato, não diferença (ver `PayloadDeEvento` no Hub): cada
 * evento traz o bloco completo da pessoa e, quando for o caso, do vínculo. Por
 * isso aplicar um aviso fora de ordem, ou reaplicar um que já passou, produz o
 * mesmo resultado — o que é justamente o que se espera de uma fila sobre HTTP,
 * que não garante ordem nenhuma.
 *
 * Eventos de vínculo mexem só no vínculo citado. Um aviso isolado não afirma
 * nada sobre os outros vínculos da pessoa; quem afirma a lista completa é a API
 * de leitura, no login.
 *
 * Eventos de estrutura (`entidade.*`/`unidade.*`) não envolvem pessoa e vão
 * direto para `HubEstruturaSyncService`.
 */
class HubEventProcessor
{
    /** Vocabulário do contrato (docs/INTEGRACAO_GOVNEX_HUB.md, passo 2). */
    public const TIPOS = [
        'pessoa.criada',
        'pessoa.alterada',
        'pessoa.desligada',
        'vinculo.criado',
        'vinculo.alterado',
        'vinculo.encerrado',
        'entidade.criada',
        'entidade.alterada',
        'entidade.removida',
        'unidade.criada',
        'unidade.alterada',
        'unidade.removida',
    ];

    public function __construct(
        private readonly HubProvisioningService $pessoas,
        private readonly HubVinculoSyncService $vinculos,
        private readonly HubEstruturaSyncService $estrutura,
    ) {}

    /**
     * @param  array<string, mixed>  $evento  payload já validado pelo controller
     * @return array<string, mixed> resumo para a resposta do webhook
     */
    public function processar(array $evento): array
    {
        $tipo = (string) $evento['tipo'];
        $dados = (array) ($evento['dados'] ?? []);

        // Eventos de estrutura não trazem pessoa: precisam sair antes da
        // exigência do bloco `pessoa` logo abaixo.
        if (str_starts_with($tipo, 'entidade.') || str_starts_with($tipo, 'unidade.')) {
            return $this->aplicarEstrutura($tipo, $dados);
        }

        $pessoa = is_array($dados['pessoa'] ?? null) ? $dados['pessoa'] : [];

        if ($pessoa === []) {
            throw new RuntimeException('Evento do Hub sem bloco de pessoa.');
        }

        // `pessoa.alterada` e os eventos de vínculo também criam a conta quando
        // ela ainda não existe aqui: é exatamente o caso que a decisão #3 quer
        // cobrir — listar e atribuir trabalho a quem nunca entrou no GAB.
        $user = $this->pessoas->sincronizarPessoa($pessoa);

        if ($user === null) {
            throw new RuntimeException('Evento do Hub sem identificador de pessoa.');
        }

        return match ($tipo) {
            'pessoa.criada', 'pessoa.alterada' => ['acao' => 'pessoa_sincronizada', 'usuario_id' => $user->id],
            'pessoa.desligada' => $this->desligar($user),
            default => $this->aplicarVinculo($user, $tipo, $dados),
        };
    }

    /**
     * `*.criada` cria o espelho (ou, se o item já estiver ligado, cai na
     * atualização — reentrega é idempotente); `*.alterada`/`*.removida`
     * aplicam nome e situação do item ligado.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws HubIndisponivelException unidade cuja entidade precisa ser resolvida no Hub, que não respondeu (500, o Hub reenvia)
     */
    private function aplicarEstrutura(string $tipo, array $dados): array
    {
        [$recurso, $acao] = explode('.', $tipo, 2);

        $bloco = is_array($dados[$recurso] ?? null) ? $dados[$recurso] : null;

        if ($bloco === null) {
            throw new RuntimeException("Evento de {$recurso} do Hub sem bloco de {$recurso}.");
        }

        if ($acao === 'criada') {
            return $recurso === 'entidade'
                ? $this->estrutura->criarEntidade($bloco)
                : $this->estrutura->criarUnidade($bloco);
        }

        $removida = $acao === 'removida';

        return $recurso === 'entidade'
            ? $this->estrutura->aplicarEntidade($bloco, $removida)
            : $this->estrutura->aplicarUnidade($bloco, $removida);
    }

    /** @return array<string, mixed> */
    private function desligar(User $user): array
    {
        $user->forceFill(['is_active' => false])->saveQuietly();
        $this->vinculos->desativarTodos($user);

        Log::info('Pessoa desligada pelo Govnex Hub.', ['usuario_id' => $user->id]);

        return ['acao' => 'pessoa_desligada', 'usuario_id' => $user->id];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function aplicarVinculo(User $user, string $tipo, array $dados): array
    {
        $vinculo = is_array($dados['vinculo'] ?? null) ? $dados['vinculo'] : null;

        if ($vinculo === null) {
            throw new RuntimeException('Evento de vínculo do Hub sem bloco de vínculo.');
        }

        // `vinculo.encerrado` é a única fonte de verdade sobre o fim do vínculo:
        // o Hub pode mandar `ativo: true` no retrato e ainda assim o evento
        // significa encerramento. O tipo vence o campo.
        if ($tipo === 'vinculo.encerrado') {
            $vinculo['ativo'] = false;
        }

        $this->vinculos->aplicarVinculo($user, $vinculo);

        return ['acao' => 'vinculo_aplicado', 'usuario_id' => $user->id];
    }
}
