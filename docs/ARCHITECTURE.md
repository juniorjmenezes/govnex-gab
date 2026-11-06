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

### Cargos (autorizacao)

O Govnex Hub so diz quem e a pessoa (autenticacao) e que ela esta vinculada a uma entidade/gabinete. **O GAB nao le papel do Hub**: a autorizacao e o cargo local do vinculo (`gabinete_membros.cargo`, `App\Enums\OfficePosition`), decidido num ponto so, `App\Authorization\OfficeAccess` (capacidades nomeadas consultadas pelas Policies). Detalhes em [INTEGRACAO_GOVNEX_HUB.md](INTEGRACAO_GOVNEX_HUB.md#cargos-locais).

| Cargo | Onde vale | O que pode |
|---|---|---|
| `root` (conta) | `users.role`, conta local | Poder total em qualquer gabinete; nunca e vinculo nem vem do Hub. |
| `VEREADOR`, `CHEFE_GABINETE` | gabinete parlamentar e gabinete independente | Tudo no gabinete, iguais: excluir, redistribuir demandas, relatorios, configuracoes, equipe e atribuicao de cargos. |
| `ASSESSOR` | gabinete parlamentar, independente e Secretaria legislativa | Le tudo, cria e altera; nao exclui, nao redistribui, nao exporta, nao configura. |
| `SECRETARIO` | Secretaria legislativa | So o modulo Secretaria legislativa; o resto, so leitura (pendente). |
| `AUDITOR` | todos os tipos | Somente leitura. |
| sem cargo | — | Falha fechada: nao entra no gabinete (`User::canAccessGabinete()` exige cargo); ve so o diretorio da entidade. |

Somente leitura do cargo auditor em duas camadas: `ResolveEntityContext` recusa com 403 qualquer metodo fora de GET/HEAD/OPTIONS quando o cargo no gabinete da URL (`OfficeContext::positionFor()`) e `AUDITOR` (exceto acoes pessoais: marcar notificacoes como lidas e registrar progresso de leitura), e as Policies de escrita consultam `OfficeAccess`. Gestao da entidade (`User::canManageEntidade()`): root ou, em gabinete independente, quem lidera o gabinete dela; Camara e Prefeitura ainda nao tem cargo de entidade (so root).

Colunas inertes, a remover na limpeza do schema: `gabinete_membros.papel` e `entidade_membros.papel` (nullable; o sync do Hub grava `null`), `entidade_convites.papel_*` (convites legados) e os valores nao-root de `users.role` (`App\Enums\AccessRole` e o resto de `App\Enums\UserRole` so existem para elas).

### Gabinete e cargo da requisicao

Nas rotas canonicas, `entidade_membros` e `gabinete_membros` (com cargo) definem acesso. `ResolveEntityContext` confere o vinculo e registra o gabinete da URL em `OfficeContext` (`setUnit`); nada e gravado na conta. Controllers, Policies, Form Requests e middlewares leem o contexto pelo `OfficeContext`:

- `officeIdFor($user)` / `officeFor($user)`: o gabinete em que a pessoa atua. Para a pessoa autenticada numa rota com contexto, e o da URL; fora disso (filas, comandos, outra pessoa que nao a autenticada), e `users.gabinete_id`.
- `requireOfficeFor($user)`: instancia nova desse gabinete, para quem vai altera-lo ou carregar relacoes (a de `officeFor()` e compartilhada pela requisicao).
- `positionFor($user)`: o cargo do vinculo ativo com o gabinete da URL; fora de uma rota com contexto, o do vinculo com `users.gabinete_id`; root nao tem cargo.

O gabinete da requisicao nao muda dentro de `runAs()`: quem atua (e com qual cargo) continua sendo o da URL, mesmo quando um fluxo grava em outro gabinete. O Inertia compartilha em `auth.user.gabinete_id` o gabinete do contexto, em `auth.user.role` so a marca `root`/`membro` e em `auth.permissions` as capacidades do cargo.

Ate 28/10/2026 o middleware projetava o gabinete e o papel do contexto em `$user->gabinete_id`/`$user->role` durante a requisicao; essa projecao foi removida depois que todos os consumidores passaram a ler o `OfficeContext`.

`users.gabinete_id` e `users.role` continuam existindo com outro significado: o gabinete em que a conta nasceu (usado pela entrada legada `RedirectLegacyTenantRoute`, que resolve o contexto apos o login e de links antigos) e a marca de root (os demais valores sao inertes; o Hub nao os reprojeta mais). Fora de uma requisicao com contexto (filas, comandos, notificacoes, destinatarios de avisos), vale o gabinete gravado por ultimo; destinatarios de "lideranca" sao os cargos `VEREADOR`/`CHEFE_GABINETE`. Criterio para remover as colunas: os consumidores fora de requisicao consultarem os vinculos e a entrada legada deixar de depender de `users.gabinete_id`.

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
