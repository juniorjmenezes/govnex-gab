# Integração com o Govnex Hub — usuários, vínculos e papéis

Status: **decisões fechadas** (22/09/2026). Nada deste documento está
implementado ainda — as 10 decisões em aberto foram resolvidas (ver tabela
abaixo); falta a implementação (passos 2 a 5 do plano sugerido).

## Decisão

Contas, vínculos com entidades e gabinetes, papéis e permissões passarão a ser geridos pelo **Govnex Hub** em curto prazo. Até lá, o GAB não evolui a própria gestão de usuários: só corrige defeitos e mantém as telas existentes funcionando.

Este documento registra como o GAB funciona hoje, o que ele precisa do Hub e as decisões que ainda precisam ser tomadas em conjunto.

## Como o GAB funciona hoje

### Conta

A tabela `users` guarda nome, e-mail, senha, 2FA, passkeys, `is_active`, `last_login_at` e dois campos herdados do modelo de "um usuário por gabinete":

- `role`: `root`, `vereador`, `chefe_gabinete` ou `assessor`;
- `gabinete_id`: o gabinete em que a conta foi criada.

`root` é o administrador da plataforma: acessa qualquer contexto, sempre com auditoria, e não pertence a gabinete.

O login é do próprio GAB (Laravel Fortify): senha, redefinição por e-mail, verificação de e-mail, 2FA por aplicativo e passkeys. Não há autocadastro. As contas são desativadas por `is_active`; o GAB **não exclui contas** (a opção de encerrar conta pelo perfil foi removida em 16/09/2026).

### Vínculos

| Tabela | Liga | Papéis |
|---|---|---|
| `entidade_membros` | pessoa ↔ entidade (gabinete independente, Câmara, Prefeitura) | `ADMINISTRADOR`, `GESTOR`, `OPERADOR`, `AUDITOR` |
| `gabinete_membros` | pessoa ↔ gabinete | `LIDER`, `GESTOR`, `MEMBRO` |
| `gabinete_liderancas` | histórico de quem liderou cada gabinete | rótulo livre (Vereador, Prefeito…) |
| `entidade_convites` | convite pendente por e-mail ou senha temporária | papel na entidade e, opcionalmente, no gabinete |

Os vínculos têm `ativo`, `ingressou_em`, `desativado_em` e `criado_por`. Remover alguém de um gabinete desativa o vínculo, sem apagar nada.

### Como a autorização usa isso

As rotas são `/entidades/{entidade}/gabinetes/{gabinete}/...`. O middleware `ResolveEntidadeContext` confere se há vínculo ativo e, durante a requisição, projeta o gabinete e o papel escolhidos nos campos antigos da conta (`LIDER` → `vereador`, `GESTOR` → `chefe_gabinete`, `MEMBRO` → `assessor`). As Policies e parte dos controllers ainda decidem por `users.role` e `users.gabinete_id`; o isolamento de dados usa o `GabineteContext`. Detalhes em [ARCHITECTURE.md](ARCHITECTURE.md#compatibilidade-do-contexto-legado).

A entrada após o login (`/dashboard`) usa `users.gabinete_id` enquanto a pessoa tiver acesso a esse gabinete; senão, o vínculo ativo mais antigo; sem nenhum, o diretório de entidades (`RedirectLegacyTenantRoute`).

### Por onde as contas nascem hoje

| Origem | Conta criada com | Vínculos |
|---|---|---|
| Administração → Gabinetes (responsável) | `role = vereador`, `gabinete_id` | derivados do `role` (`EntidadeMembershipService::syncLegacyUser`) |
| Equipe do gabinete | `role` chefe/assessor, `gabinete_id` | entidade `OPERADOR` + gabinete `GESTOR`/`MEMBRO` |
| Convite da entidade | `role` derivado do papel convidado; `gabinete_id` pode ficar vazio | os do convite |
| Administração → Usuários root | `role = root` | nenhum |

### Problemas conhecidos, que ficam para o Hub

- `gabinete_membros.papel` e `users.role` descrevem a mesma coisa; o mapeamento entre eles está repetido no middleware de contexto, no serviço de convites e no `syncLegacyUser`.
- `GESTOR` existe nos dois enums com significados diferentes; `AUDITOR` pode ser concedido, mas nenhuma regra o diferencia de `OPERADOR`.
- `users.role` não acompanha promoções: fora de uma requisição com contexto (filas, comandos, notificações), vale o papel da criação.
- Existem duas noções de inativo (`users.is_active` e `gabinete_membros.ativo`) e nenhuma tela mostra as duas juntas.
- Senhas são redefinidas por `window.prompt` na Equipe e na administração; `exige_troca_senha` só existe nos convites.

## O que o GAB continua precisando localmente

A tabela `users` não pode desaparecer: ela é chave estrangeira de dados operacionais e de auditoria. Alguns exemplos:

- `criado_por_id` em demandas, compromissos, atendimentos e eventos (`restrictOnDelete`);
- responsáveis e atendentes de demandas, agenda, atendimentos e eventos (`nullOnDelete`);
- autores de encaminhamentos, atualizações, favoritos, convites, transferências e eventos de contexto;
- `gabinete_membros`, `entidade_membros`, `compromisso_participantes` e `whatsapp_contatos` (`cascadeOnDelete`).

Por isso, com o Hub, o GAB mantém uma **cópia local** de cada pessoa e dos seus vínculos, alimentada pelo Hub, e **nunca exclui fisicamente** uma conta: desativa.

O GAB também precisa listar pessoas que ainda não entraram no sistema — por exemplo, para escolher o responsável de uma demanda ou os participantes de um compromisso.

## Proposta

1. **Fonte da verdade:** o Hub cria, altera e desativa contas e vínculos. As telas do GAB que fazem isso (Equipe, convites, responsável do gabinete, usuários root) passam a ser somente leitura ou apontam para o Hub.
2. **Login:** SSO pelo Hub via OpenID Connect. O GAB deixa de guardar senha, 2FA e passkeys e confia na autenticação do Hub.
3. **Identificador:** nova coluna `users.hub_user_id` (única), preenchida com o `sub` do Hub. O casamento é sempre por esse identificador; o e-mail é só um dado da pessoa.
4. **Provisionamento:** combinação de dois caminhos.
   - **No login**, o GAB cria ou atualiza a pessoa e os vínculos a partir das claims recebidas.
   - **Por webhook assinado**, o Hub avisa criação, alteração e desativação de pessoas e vínculos, para o GAB conseguir listar quem ainda não entrou e bloquear quem foi desligado sem esperar o próximo login.
5. **Papéis:** o Hub envia os papéis já no vocabulário do GAB (`entidade_membros.papel` e `gabinete_membros.papel`), ou um vocabulário próprio com mapeamento documentado aqui. `users.role` passa a ser derivado e deixa de ser editado.
6. **Desativação:** desligar alguém no Hub desativa a conta local (`is_active = false`) e encerra as sessões ativas no GAB.

## Decisões fechadas (22/09/2026)

| # | Pergunta | Decisão | Observação |
|---|---|---|---|
| 1 | Protocolo de login | **OIDC** | Socialite + provider próprio no Hub. O Hub vira um Identity Provider — hoje não há Passport/Socialite/OIDC no `composer.json` do Hub, é infraestrutura nova. |
| 2 | Período de transição | **Corte direto** | Sem login local e SSO lado a lado. Login local do GAB é desativado no dia do corte — exige que a carga inicial (decisão #7) e a base técnica (webhook, `hub_user_id`) estejam prontas e testadas antes do corte. |
| 3 | Provisionamento | **Login + webhook** | No login, o GAB sincroniza a própria conta; webhook assinado do Hub avisa mudanças em tempo real (necessário para listar quem ainda não entrou e bloquear desligados sem esperar login). |
| 4 | Contrato do webhook | **Eventos por pessoa e por vínculo** | Não é snapshot periódico. Precisa de assinatura, idempotência e fila de reprocessamento por evento perdido. |
| 5 | Vocabulário de papéis | **Hub tem vocabulário próprio + mapa** | Chance de corrigir os problemas já documentados (GESTOR duplicado, AUDITOR sem regra própria) numa modelagem nova no Hub, com mapeamento documentado para `entidade_membros.papel`/`gabinete_membros.papel` do GAB. |
| 6 | Usuários root | **Mantidos locais no GAB** | Preserva acesso de emergência se o Hub cair — coerente com a decisão #10. |
| 7 | Contas já existentes | **Vínculo único por e-mail** | Cada e-mail do GAB casa com uma pessoa no Hub na carga inicial. E-mails duplicados ou divergentes precisam de revisão manual antes do corte. |
| 8 | 2FA e passkeys atuais | **Hub precisa oferecer o equivalente antes do corte** | O corte só acontece quando o Hub já tiver 2FA/passkey funcionando — não pode reduzir a segurança das contas na virada. Bloqueia a decisão #2 até estar pronto. |
| 9 | WhatsApp e consentimento | **Ficam no GAB** | Continuam dado operacional do GAB; fora do escopo de identidade/acesso do Hub. |
| 10 | Queda do Hub | **Aceita sessão já aberta até expirar** | Só login novo é bloqueado quando o Hub está fora do ar; quem já estava logado continua trabalhando até a sessão expirar. Root local (#6) cobre o acesso administrativo de emergência. |

## Plano sugerido

1. ~~**Contrato:** fechar as decisões acima e o formato das claims e dos webhooks.~~ ✅ Decisões fechadas em 22/09/2026 (falta ainda o formato exato das claims OIDC e do payload dos webhooks, item de detalhe técnico do passo 2).
2. **Base:** no Hub, provider OIDC + 2FA/passkeys próprios (bloqueia o corte, decisão #8) + vocabulário de papéis próprio com mapa (decisão #5) + endpoint de webhook assinado (eventos por pessoa/vínculo, decisão #4). No GAB, `hub_user_id`, endpoint que recebe o webhook (assinatura + idempotência) e serviço que aplica pessoa e vínculos localmente, sem mudar o login ainda.
3. **Carga inicial:** vincular as contas existentes por e-mail (decisão #7) e revisar as divergências (duplicados, e-mails que não batem).
4. **Corte:** login local do GAB desativado; SSO pelo Hub vira obrigatório (decisão #2). Telas de gestão de usuários do GAB passam a somente leitura. Sessões já abertas continuam até expirar se o Hub cair (decisão #10); root continua local (decisão #6).
5. **Limpeza:** remover senha, 2FA e passkeys locais do GAB, `users.role` como dado editável e as rotas legadas que dependem de `users.gabinete_id`.

## Até a integração

- Não criar telas ou regras novas de gestão de usuários no GAB.
- Nunca excluir contas fisicamente; desativar.
- Corrigir apenas defeitos que afetem o uso atual.
