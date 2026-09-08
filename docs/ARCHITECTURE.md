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

O administrador da plataforma pode entrar em qualquer contexto, sempre com evento auditado. Um gestor da entidade pode operar recursos institucionais, mas so acessa dados privados de um gabinete quando tambem possui vinculo com ele.

### Compatibilidade do contexto legado

Nas rotas canonicas, `entidade_membros` e `gabinete_membros` definem acesso e papel. O middleware contextual projeta temporariamente o gabinete e o papel selecionados nos atributos legados de `User` para manter Policies e controladores operacionais antigos funcionando durante a transicao. Essa projecao existe apenas durante a requisicao e nao altera o cadastro persistido do usuario.

As colunas `users.gabinete_id` e `users.role` so poderao ser removidas depois que todos os consumidores operacionais usarem `EntidadeContext`, `GabineteContext`, `entidadeRole()` e `gabineteRole()`, e as rotas legadas deixarem de ser necessarias. Ate la, elas representam respectivamente o gabinete padrao e o papel global de administracao da plataforma, nao a fonte canonica de autorizacao contextual.

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
