# Integração com o Govnex Hub — usuários, vínculos e papéis

Status: **decisões fechadas** (22/09/2026); **passo 2 concluído dos dois lados**
(23/09/2026). O Hub é provedor OIDC, tem 2FA/passkeys, webhook assinado com
caixa de saída e API de leitura protegida; o GAB é o **piloto consumidor** —
entra por SSO, recebe os webhooks e espelha pessoas e vínculos. Faltam os
passos 3 a 5 (carga inicial revisada, corte em produção e limpeza), e GRI e GPC
replicam o padrão do GAB depois.

## Decisão

Contas, vínculos com entidades e gabinetes, papéis e permissões passarão a ser geridos pelo **Govnex Hub** em curto prazo. Até lá, o GAB não evolui a própria gestão de usuários: só corrige defeitos e mantém as telas existentes funcionando.

Este documento registra como o GAB funciona hoje, o que ele precisa do Hub e as decisões que ainda precisam ser tomadas em conjunto.

## Como o GAB funciona hoje

### Conta

A tabela `users` guarda nome, e-mail, senha, 2FA, passkeys, `is_active`, `last_login_at` e dois campos herdados do modelo de "um usuário por gabinete":

- `role`: `root`, `administrador`, `operador` ou `auditor` (projeção do papel do vínculo; ver decisão #5);
- `gabinete_id`: o gabinete em que a conta foi criada.

`root` é o administrador da plataforma: acessa qualquer contexto, sempre com auditoria, e não pertence a gabinete.

O login é do próprio GAB (Laravel Fortify): senha, redefinição por e-mail, verificação de e-mail, 2FA por aplicativo e passkeys. Não há autocadastro. As contas são desativadas por `is_active`; o GAB **não exclui contas** (a opção de encerrar conta pelo perfil foi removida em 16/09/2026).

### Vínculos

| Tabela | Liga | Papéis |
|---|---|---|
| `entidade_membros` | pessoa ↔ entidade (gabinete independente, Câmara, Prefeitura) | `ADMINISTRADOR`, `OPERADOR`, `AUDITOR` |
| `gabinete_membros` | pessoa ↔ gabinete | `ADMINISTRADOR`, `OPERADOR`, `AUDITOR` |
| `entidade_convites` | convite pendente por e-mail ou senha temporária | papel na entidade e, opcionalmente, no gabinete |

Os vínculos têm `ativo`, `ingressou_em`, `desativado_em` e `criado_por`. Remover alguém de um gabinete desativa o vínculo, sem apagar nada.

### Como a autorização usa isso

As rotas são `/entidades/{entidade}/gabinetes/{gabinete}/...`. O middleware `ResolveEntidadeContext` confere se há vínculo ativo e, durante a requisição, projeta o gabinete e o papel escolhidos nos campos antigos da conta, 1:1 (`ADMINISTRADOR` → `administrador`, `OPERADOR` → `operador`, `AUDITOR` → `auditor`). Papel projetado `auditor` recebe 403 em qualquer escrita dentro do contexto. As Policies e parte dos controllers ainda decidem por `users.role` e `users.gabinete_id`; o isolamento de dados usa o `GabineteContext`. Detalhes em [ARCHITECTURE.md](ARCHITECTURE.md#compatibilidade-do-contexto-legado).

A entrada após o login (`/dashboard`) usa `users.gabinete_id` enquanto a pessoa tiver acesso a esse gabinete; senão, o vínculo ativo mais antigo; sem nenhum, o diretório de entidades (`RedirectLegacyTenantRoute`).

### Por onde as contas nascem hoje

| Origem | Conta criada com | Vínculos |
|---|---|---|
| Administração → Gabinetes (responsável) | `role = administrador`, `gabinete_id` | derivados do `role` (`EntidadeMembershipService::syncLegacyUser`) |
| Equipe do gabinete | `role` administrador/operador/auditor, `gabinete_id` | gabinete com o papel escolhido; entidade com o mesmo papel (administrador só em gabinete independente) |
| Convite da entidade | `role` derivado do papel convidado; `gabinete_id` pode ficar vazio | os do convite |
| Administração → Usuários root | `role = root` | nenhum |

### Problemas conhecidos, que ficam para o Hub

- `gabinete_membros.papel` e `users.role` descrevem a mesma coisa; o mapeamento entre eles está repetido no middleware de contexto, no serviço de convites e no `syncLegacyUser`.
- ~~`GESTOR` existe nos dois enums com significados diferentes; `AUDITOR` pode ser concedido, mas nenhuma regra o diferencia de `OPERADOR`.~~ Resolvido em 23/09/2026: um só enum (`AccessRole`) e auditor somente leitura.
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
| 1 | Protocolo de login | **OIDC** | ✅ Implementado no Hub em 23/09/2026. O Hub virou Identity Provider com `laravel/passport` (OAuth2 Authorization Code + PKCE) e uma camada fina própria para o que falta de OIDC — `id_token`, descoberta, JWKS e `userinfo`. Não existe pacote maduro de *provider* OIDC para Laravel 13; só de cliente. No GAB, o consumo será por Socialite com provider genérico apontado ao documento de descoberta. Detalhes no passo 2 do plano. |
| 2 | Período de transição | **Corte direto** | Sem login local e SSO lado a lado. Login local do GAB é desativado no dia do corte — exige que a carga inicial (decisão #7) e a base técnica (webhook, `hub_user_id`) estejam prontas e testadas antes do corte. |
| 3 | Provisionamento | **Login + webhook** | No login, o GAB sincroniza a própria conta; webhook assinado do Hub avisa mudanças em tempo real (necessário para listar quem ainda não entrou e bloquear desligados sem esperar login). |
| 4 | Contrato do webhook | **Eventos por pessoa e por vínculo** | Não é snapshot periódico. Precisa de assinatura, idempotência e fila de reprocessamento por evento perdido. |
| 5 | Vocabulário de papéis | **Fechado (23/09/2026): `administrador` · `operador` · `auditor`, mais `root` local** | Padrão do ecossistema, igual em todos os produtos — ver "Vocabulário de papéis" abaixo. |
| 6 | Usuários root | **Mantidos locais no GAB** | Preserva acesso de emergência se o Hub cair — coerente com a decisão #10. |
| 7 | Contas já existentes | **Vínculo único por e-mail** | Cada e-mail do GAB casa com uma pessoa no Hub na carga inicial. E-mails duplicados ou divergentes precisam de revisão manual antes do corte. |
| 8 | 2FA e passkeys atuais | **Hub precisa oferecer o equivalente antes do corte** | O corte só acontece quando o Hub já tiver 2FA/passkey funcionando — não pode reduzir a segurança das contas na virada. Bloqueia a decisão #2 até estar pronto. |
| 9 | WhatsApp e consentimento | **Ficam no GAB** | Continuam dado operacional do GAB; fora do escopo de identidade/acesso do Hub. |
| 10 | Queda do Hub | **Aceita sessão já aberta até expirar** | Só login novo é bloqueado quando o Hub está fora do ar; quem já estava logado continua trabalhando até a sessão expirar. Root local (#6) cobre o acesso administrativo de emergência. |

## Plano sugerido

1. ~~**Contrato:** fechar as decisões acima e o formato das claims e dos webhooks.~~ ✅ Decisões fechadas em 22/09/2026 (falta ainda o formato exato das claims OIDC e do payload dos webhooks, item de detalhe técnico do passo 2).
2. **Base:** ✅ **concluído em 23/09/2026 dos dois lados** — Hub como provedor, GAB como primeiro consumidor. O contrato efetivo do lado do GAB está em "O que o GAB implementou", no fim deste passo; é o que GRI e GPC replicam.

   O que o Hub passou a oferecer:

   **Endpoints OIDC** — `issuer` é a `APP_URL` do Hub, sem barra final.

   | Endpoint | Para quê |
   |---|---|
   | `GET /.well-known/openid-configuration` | Descoberta. É o único endereço que o GAB precisa configurar. |
   | `GET /oauth/authorize` | Código de autorização. Exige `code_challenge_method=S256`; `response_type` só aceita `code`. |
   | `POST /oauth/token` | Troca do código pelo par de tokens. A resposta traz `id_token` quando o pedido incluiu o escopo `openid`. |
   | `GET /oauth/jwks` | Chave pública RSA (RS256) que valida o `id_token`. O `kid` é o thumbprint RFC 7638 da própria chave. |
   | `GET\|POST /oauth/userinfo` | Perfil do portador do access token. |

   Escopos: `openid`, `profile`, `email`. Cliente OAuth por sistema, criado com
   `php artisan hub:registrar-cliente-oidc GAB --redirect=<uri>`; o id fica em
   `sistemas.oauth_client_id` e o segredo nas tabelas do Passport. Clientes do
   ecossistema são de primeira parte e **não** passam por tela de consentimento.

   **Claims do `id_token`** (JWS RS256):

   ```json
   {
     "iss": "https://hub.govnex.example", "sub": "42",
     "aud": "<client_id>", "iat": 1758..., "nbf": 1758...,
     "exp": 1758..., "auth_time": 1758..., "jti": "<uuid>",
     "name": "Ana Sousa", "email": "ana@exemplo.gov.br",
     "email_verified": true
   }
   ```

   `sub` é o id da pessoa no Hub, **como string** — é o valor que vira
   `users.hub_user_id` no GAB (decisão #3). Vínculo e papel não viajam em claim:
   mudam mais rápido do que um token vive, e quem precisa deles chama
   `GET /api/v1/sistemas/{codigo}/pessoas/{usuario}`, que responde o estado de
   agora. O `/oauth/userinfo` devolve as mesmas claims de perfil mais `sub`.
   Não há claim `nonce` — se o GAB usar `nonce`, ele não volta no token.

   **Webhook assinado** (decisão #4) — `POST` no `sistemas.webhook_url`, com
   `X-Hub-Timestamp`, `X-Hub-Nonce` e `X-Hub-Signature: sha256=<hex>`. O HMAC-SHA256
   usa `sistemas.client_secret` sobre o canônico
   `MÉTODO\nCAMINHO?QUERY\nTIMESTAMP\nNONCE\nsha256(corpo)` — o mesmo esquema que o
   GAB já valida em `WhatsAppCallbackSignatureValidator`. Tipos de evento:
   `pessoa.criada`, `pessoa.alterada`, `pessoa.desligada`, `vinculo.criado`,
   `vinculo.alterado`, `vinculo.encerrado`.

   ```json
   {
     "id": "<uuid do evento>",
     "tipo": "vinculo.alterado",
     "sistema": "GAB",
     "ocorrido_em": "2026-09-23T13:40:00-03:00",
     "dados": {
       "pessoa": {
         "id": "42", "nome": "Ana Sousa", "email": "ana@exemplo.gov.br",
         "documento": null, "telefone": null,
         "ativo": true, "email_verificado": true,
         "atualizado_em": "2026-09-23T13:40:00-03:00"
       },
       "vinculo": {
         "id": "7", "entidade_id": "3", "unidade_id": "12",
         "papel": "administrador", "escopo_hierarquico": false,
         "ativo": true, "inicio_em": null, "fim_em": null
       }
     }
   }
   ```

   É retrato, não diferença: o GAB aplica o bloco recebido por cima do que tem e
   fica correto mesmo se um aviso anterior nunca chegou. `id` é o que o GAB usa
   para descartar reentrega. Eventos de pessoa trazem só `dados.pessoa`. Os avisos
   ficam numa caixa de saída (`webhook_eventos`) e o que falha é reenfileirado por
   `hub:reprocessar-webhooks-pendentes`, agendado a cada cinco minutos.

   **2FA e passkeys** (decisão #8) — `laravel/fortify` com `laravel/passkeys`, a
   mesma dupla que o GAB já usa, com 2FA confirmado (`confirm: true`) e
   confirmação de senha para gerenciar os dois. Tela em `/settings/security`.

   **API de leitura** — `/api/v1/*` estava aberta e passou a exigir
   `Authorization: Bearer <client_secret do sistema>`. O mesmo segredo assina os
   webhooks; o segredo do handshake OIDC é outro, e mora no Passport.

   **Vocabulário de papéis** (decisão #5) — fechado em 23/09/2026; ver a seção
   "Vocabulário de papéis" abaixo.

   ### O que o GAB implementou (contrato efetivo — referência para GRI e GPC)

   **Rotas do consumidor.**

   | Rota | Para quê |
   |---|---|
   | `GET /auth/hub/redirect` (`hub.redirect`) | Manda a pessoa ao Hub. `state` + PKCE S256 na sessão. `throttle:30,1`. |
   | `GET /auth/hub/callback` (`hub.callback`) | Volta do Hub: troca o código, espelha pessoa e vínculos, abre a sessão. É o `--redirect` do `hub:registrar-cliente-oidc`. `throttle:30,1`. |
   | `POST /api/integrations/hub/webhook` (`api.hub.webhook`) | Recebe os avisos. Sem `auth`: quem autentica é o HMAC. `throttle:240,1`. É o valor de `sistemas.webhook_url` no Hub. |

   O login local do Fortify **não foi removido**: `Fortify::authenticateUsing`
   passou a recusar quem não é `root` (decisões #2, #6 e #10). A tela de login
   lidera com "Entrar com Govnex Hub" e mantém o formulário de senha recolhido,
   como acesso de emergência; `/settings/security` (senha, 2FA, passkeys) ficou
   restrita a `root` e redireciona os demais para o painel.

   **Variáveis de ambiente** (ver `.env.example` e `config/services.php`, chave
   `services.hub`). Sem `HUB_BASE_URL` + `HUB_OIDC_CLIENT_ID` +
   `HUB_OIDC_CLIENT_SECRET` preenchidos, a tela de login nem oferece o botão do
   Hub — ambiente novo continua entrando pelo acesso local até o SSO ser
   configurado.

   | Variável | Origem | Papel |
   |---|---|---|
   | `HUB_BASE_URL` | endereço do Hub | Base da descoberta OIDC e da API. |
   | `HUB_SISTEMA_CODIGO` | fixo, `GAB` | Código do `Sistema` no Hub; entra na URL da API e é conferido no campo `sistema` do webhook. |
   | `HUB_OIDC_CLIENT_ID` / `HUB_OIDC_CLIENT_SECRET` | `php artisan hub:registrar-cliente-oidc GAB --redirect=<HUB_OIDC_REDIRECT>`, rodado **no Hub** | Handshake OIDC. |
   | `HUB_OIDC_REDIRECT` | `${APP_URL}/auth/hub/callback` | Precisa bater exatamente com o `--redirect` registrado. |
   | `HUB_API_SECRET` | `sistemas.client_secret` do Sistema GAB no Hub | Duplo papel: `Bearer` na leitura de `/api/v1/*` e chave HMAC que valida o webhook. |
   | `HUB_TIMEOUT` | opcional, 15 | Timeout das chamadas ao Hub. |
   | `HUB_WEBHOOK_WINDOW_SECONDS` | opcional, 300 | Tolerância do `X-Hub-Timestamp`. |

   **Peças.** `app/Services/Hub/`: `HubSocialiteProvider` (driver `hub` do
   Socialite, registrado em `AppServiceProvider`; sem `nonce`, perfil pelo
   `userinfo`), `GovnexHubApiClient` (leitura de
   `/api/v1/sistemas/{codigo}/pessoas/{usuario}`), `HubProvisioningService`
   (casa a conta por `hub_user_id` e, na falta dele, por e-mail — a carga
   inicial da decisão #7 acontecendo no primeiro acesso —, ou cria),
   `HubVinculoSyncService` (escreve `entidade_membros`/`gabinete_membros` e
   reprojeta `users.role`/`users.gabinete_id`), `HubPapelMapper` (traduz o
   `papel` opaco para `AccessRole`, decisão #5), `HubCallbackSignatureValidator`
   e `HubEventProcessor`. Controllers: `Auth/HubAuthController` e
   `Api/HubCallbackController`. Colunas de ponte: `users.hub_user_id` (já
   existia), `entidades.hub_entidade_id` e `gabinetes.hub_unidade_id`.

   **Duas regras que valem para quem replicar.** Retrato, não diferença: o login
   aplica a lista completa e desativa o que não veio; o webhook mexe só no
   vínculo citado, porque um aviso isolado não afirma nada sobre os outros.
   E nada apaga linha — vínculo encerrado vira `ativo = false` com
   `desativado_em`, que é o histórico de auditoria do GAB, não do Hub.

   **Espelhamento da estrutura (antes do primeiro login SSO).** Rode
   `php artisan hub:espelhar-estrutura --dry-run` e, se o relatório estiver
   coerente, `php artisan hub:espelhar-estrutura`. O comando lê a estrutura pela
   API do Hub, casa entidade por `slug` e gabinete (dentro da entidade) por
   `slug` — a mesma regra do `ImportadorDoGab` no Hub — e preenche
   `hub_entidade_id`/`hub_unidade_id` só onde estão nulos; é idempotente e nunca
   sobrescreve um id diferente (reporta conflito e sai com falha). Sem esse
   passo, vínculos não mapeáveis são ignorados com aviso em log e o login
   **não desativa** os vínculos locais existentes (só uma lista vazia
   deliberada, ou `pessoa.desligada`, desliga tudo).
3. **Carga inicial:** vincular as contas existentes por e-mail (decisão #7) e revisar as divergências (duplicados, e-mails que não batem).
4. **Corte:** login local do GAB desativado; SSO pelo Hub vira obrigatório (decisão #2). Telas de gestão de usuários do GAB passam a somente leitura. Sessões já abertas continuam até expirar se o Hub cair (decisão #10); root continua local (decisão #6).
5. **Limpeza:** remover senha, 2FA e passkeys locais do GAB, `users.role` como dado editável e as rotas legadas que dependem de `users.gabinete_id`.

## Vocabulário de papéis (decisão #5, fechada em 23/09/2026)

Quatro níveis, iguais em todos os produtos do ecossistema:

| Papel | Onde vive | Significado |
|---|---|---|
| `root` | conta local de cada produto | Administração da plataforma. **Não é vínculo** e não vem do Hub (decisão #6). |
| `administrador` | vínculo (entidade ou unidade) | Gerencia o contexto: equipe, convites, configurações, exclusões. |
| `operador` | vínculo | Cria e altera registros operacionais. |
| `auditor` | vínculo | Somente leitura. |

- O Hub continua tratando `Vinculo.papel` como **texto opaco** e não interpreta
  permissão; cada produto entende ao menos esses três valores. O papel vale por
  vínculo.
- Funções de negócio (líder, gestor, vereador, chefe de gabinete, dono do risco,
  gestor de unidade) **não** são papel no Hub: viram atribuição dentro do
  produto.
- No GAB, o `HubPapelMapper` aceita só `administrador`/`operador`/`auditor`
  (sem distinção de caixa) e grava `AccessRole` (`ADMINISTRADOR`/`OPERADOR`/`AUDITOR`)
  em `gabinete_membros.papel` e `entidade_membros.papel`. Papel desconhecido vira
  **auditor** (menor privilégio) com aviso em log; vínculo com papel `root` é
  ignorado. Administrador de uma unidade só é administrador da **entidade**
  quando ela é gabinete independente; em Câmara ou Prefeitura vira operador da
  entidade.
- `users.role` é projeção 1:1 do papel do vínculo principal (`administrador`,
  `operador`, `auditor`) ou `root`. A ponte continua como dívida registrada em
  [ARCHITECTURE.md](ARCHITECTURE.md#compatibilidade-do-contexto-legado).

Mapeamento para os papéis antigos:

| Produto | Papel antigo | Papel no ecossistema |
|---|---|---|
| GAB | vereador, chefe de gabinete (`LIDER`, `GESTOR`) | administrador |
| GAB | assessor (`MEMBRO`) | operador |
| GRI (quando integrado) | `administrator` | administrador |
| GRI (quando integrado) | `executive` | auditor |
| GRI (quando integrado) | `risk_management`, `unit_manager`, `risk_owner`, `collaborator` | operador (as funções viram atribuições no GRI) |
| GPC (quando integrado) | `administrator` | administrador |
| GPC (quando integrado) | `analyst` | operador; auditor para revisores só de leitura |

## Até a integração

- Não criar telas ou regras novas de gestão de usuários no GAB.
- Nunca excluir contas fisicamente; desativar.
- Corrigir apenas defeitos que afetem o uso atual.
