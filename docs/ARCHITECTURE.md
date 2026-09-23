# Arquitetura do GOVNEX GAB

O GOVNEX GAB e um monolito modular multientidade. Laravel concentra regras de dominio, persistencia, autenticacao, autorizacao, filas e integracoes. Inertia transporta props tipadas e React renderiza a interface.

## Tenancy hierarquico

`entidades` e o tenant raiz. `gabinetes` permanece como identificador tecnico dos gabinetes, preservando as FKs e os dados operacionais existentes. A hierarquia termina em `entidade -> gabinete -> equipe`.

- `EntidadeContext` resolve a entidade e seus vinculos.
- `GabineteContext` resolve um gabinete explicitamente pertencente a entidade.
- `entidade_membros` e `gabinete_membros` sao as fontes contextuais de autorizacao.
- `users.gabinete_id` e `users.role` permanecem apenas como compatibilidade caracterizada.
- rotas canonicas usam `/entidades/{entidade}` e `/entidades/{entidade}/gabinetes/{gabinete}`;
- rotas legadas de leitura e escrita redirecionam com auditoria, sem executar a operacao antes do contexto explicito.

O administrador da plataforma pode entrar em qualquer contexto, sempre com evento auditado. Um administrador da entidade pode operar recursos institucionais, mas so acessa dados privados de um gabinete quando tambem possui vinculo com ele.

### Papeis de acesso

Quatro niveis, iguais em todo o ecossistema GOVNEX (decisao #5 de [INTEGRACAO_GOVNEX_HUB.md](INTEGRACAO_GOVNEX_HUB.md)):

| Papel | Onde vive | O que pode |
|---|---|---|
| `root` | `users.role`, conta local | Administracao da plataforma; nunca e vinculo nem vem do Hub. |
| administrador | `AccessRole::Administrator` (`ADMINISTRADOR`) | Tudo no contexto: equipe, convites, configuracoes, exclusoes, relatorios. |
| operador | `AccessRole::Operator` (`OPERADOR`) | Cria e altera registros operacionais; nao exclui demandas, cidadaos, agenda, atendimentos e eventos, nem gerencia equipe. |
| auditor | `AccessRole::Auditor` (`AUDITOR`) | Somente leitura. |

`App\Enums\AccessRole` e o unico enum de papel de vinculo, usado por `entidade_membros.papel`, `gabinete_membros.papel` e pelos convites. Administrador de gabinete so e administrador da **entidade** quando ela e gabinete independente; em Camara ou Prefeitura ele e operador da entidade (`AccessRole::forEntidadeOfUnit`). Funcoes de negocio (vereador, chefe de gabinete, lideranca) nao sao papel: `gabinetes.vereador_nome` e `gabinetes.candidato_titular_id` sao dado de dominio do gabinete, sem efeito em autorizacao. O historico de liderancas (`gabinete_liderancas`, model `GabineteLideranca`) foi removido em 23/09/2026 por decisao do usuario: nao tinha leitores, e papel de acesso nao implica lideranca. O rotulo contextual do titular (Vereador, Prefeito, Secretario) continua em `GabineteType::leaderLabel()`.

Somente leitura do auditor em duas camadas: `ResolveEntidadeContext` recusa com 403 qualquer metodo fora de GET/HEAD/OPTIONS quando o papel projetado e auditor (exceto acoes pessoais: marcar notificacoes como lidas e registrar progresso de leitura), e as Policies de escrita exigem `role->canWrite()`. Rotas fora do contexto (perfil proprio, logout) nao passam por esse middleware.

### Compatibilidade do contexto legado

Nas rotas canonicas, `entidade_membros` e `gabinete_membros` definem acesso e papel. O middleware contextual projeta temporariamente o gabinete e o papel selecionados nos atributos legados de `User` para manter Policies e controladores operacionais antigos funcionando durante a transicao. Essa projecao existe apenas durante a requisicao e nao altera o cadastro persistido do usuario.

A projecao e 1:1: `AccessRole` do vinculo com o gabinete em contexto vira `UserRole` (`administrador`, `operador`, `auditor`); `root` nunca e sobrescrito.

As colunas `users.gabinete_id` e `users.role` so poderao ser removidas depois que todos os consumidores operacionais usarem `EntidadeContext`, `GabineteContext`, `entidadeRole()` e `gabineteRole()`, e as rotas legadas deixarem de ser necessarias. Ate la, elas representam respectivamente o gabinete padrao e o papel global de administracao da plataforma, nao a fonte canonica de autorizacao contextual.

**Divida registrada — ponte `users.role`.** As Policies ainda decidem por `users.role`, projetado por requisicao. Foi mantido de proposito na simplificacao de papeis (23/09/2026) para reduzir o raio da mudanca. Fora de uma requisicao com contexto (filas, comandos, notificacoes), `users.role` vale o que foi gravado por ultimo (criacao da conta ou reprojecao do Hub), e nao o papel em cada gabinete. Criterio de remocao: Policies passarem a ler `gabineteRole()`/`entidadeRole()` do contexto resolvido e os consumidores fora de requisicao consultarem os vinculos.

Todo gabinete novo pertence obrigatoriamente a uma entidade. O schema de desenvolvimento nao admite `gabinetes.entidade_id` nulo.

## Limites principais

- Todo registro operacional pertence a um `gabinete_id` definido pelo backend e a uma entidade derivada do gabinete.
- contextos, vinculos, scopes, Policies, Form Requests e middleware protegem o isolamento.
- Actions coordenam mudancas de estado; Services concentram regras reutilizaveis.
- Jobs usam filas separadas para operacoes gerais, TSE e WhatsApp.
- Arquivos privados passam por autenticacao, Policy e storage nao publico.
- APIs externas ficam atras de clientes e servicos proprios, com idempotencia e reconciliacao.

## Modulos por gabinete

`GabineteModule` e `GabineteModuleCatalog` definem o catalogo e as dependencias. `GabineteModuleManager` consulta e altera o estado de forma transacional. O middleware `module:<CODIGO>` protege rotas tenant, enquanto `auth.modules` permite adaptar a navegacao.

Ausencia de registro significa modulo desativado. O backfill inicial ativa todos os modulos para gabinetes existentes. Desativacao preserva dados e bloqueia somente acesso e novos processamentos. O contrato completo esta em [`govnexgabmodulos.md`](../govnexgabmodulos.md).

## Licenciamento e modulos da entidade

`EntidadeModuleManager` combina plano comercial versionado, licenca, ajustes, ativacao institucional, distribuicao a gabinetes e dependencias. Cotas bloqueiam apenas novas acoes mensuradas e nunca impedem consulta, exportacao ou regularizacao.

WhatsApp e associado explicitamente a uma entidade e a uma conta retornada pelo Gateway. Nao existe fallback automatico de conta.

Transferencias de gabinete sao transacionais, exigem mesma jurisdicao, aceite das entidades e aprovacao da plataforma. Dados privados acompanham o gabinete.

## Processamentos assincronos

Jobs que dependem de modulo validam sua disponibilidade antes do despacho e antes do efeito final. Relatorios podem ser cancelados, lembretes interrompidos, sincronizacoes TSE ignoradas e notificacoes WhatsApp suprimidas. Callbacks externos permanecem acessiveis para reconciliar operacoes ja aceitas.

## Operacao

O ambiente de producao roda na `f3-vps`; Compose, scripts, backup e reconciliacao pertencem ao repositorio separado `C:\xampp82\htdocs\f3-infra`. Este repositorio documenta somente os contratos da aplicacao. Consulte [`docs/DEPLOY.md`](DEPLOY.md) antes de qualquer publicacao.
