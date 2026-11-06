# Integração com o Govnex Hub — usuários, vínculos e papéis

Status: **decisões fechadas** (22/09/2026); **passo 2 concluído dos dois lados**
(23/09/2026). O Hub é provedor OIDC, tem 2FA/passkeys, webhook assinado com
caixa de saída e API de leitura protegida; o GEP é o **piloto consumidor** —
entra por SSO, recebe os webhooks e espelha pessoas e vínculos. A carga inicial
(decisão #7) acontece automaticamente no primeiro login de cada pessoa; as
telas de Equipe e convite de entidade do GEP ficaram somente leitura em
24/09/2026 (passo 4 do plano abaixo). Falta o corte em produção (senha/2FA
locais dos não-root) e a limpeza (passo 5), e GRI e GPC replicam o padrão do
GEP depois.

## Decisão

Contas, vínculos com entidades e gabinetes, papéis e permissões passarão a ser geridos pelo **Govnex Hub** em curto prazo. Até lá, o GEP não evolui a própria gestão de usuários: só corrige defeitos e mantém as telas existentes funcionando.

Este documento registra como o GEP funciona hoje, o que ele precisa do Hub e as decisões que ainda precisam ser tomadas em conjunto.

## Como o GEP funciona hoje

### Conta

A tabela `users` guarda nome, e-mail, senha, 2FA, passkeys, `is_active`, `last_login_at` e dois campos herdados do modelo de "um usuário por gabinete":

- `role`: `root`, `administrador`, `operador` ou `auditor` (projeção do papel do vínculo; ver decisão #5);
- `gabinete_id`: o gabinete em que a conta foi criada.

`root` é o administrador da plataforma: acessa qualquer contexto, sempre com auditoria, e não pertence a gabinete.

O login é do próprio GEP (Laravel Fortify): senha, redefinição por e-mail, verificação de e-mail, 2FA por aplicativo e passkeys. Não há autocadastro. As contas são desativadas por `is_active`; o GEP **não exclui contas** (a opção de encerrar conta pelo perfil foi removida em 16/09/2026).

### Vínculos

| Tabela | Liga | Papéis |
|---|---|---|
| `entidade_membros` | pessoa ↔ entidade (gabinete independente, Câmara, Prefeitura) | `ADMINISTRADOR`, `OPERADOR`, `AUDITOR` |
| `gabinete_membros` | pessoa ↔ gabinete | `ADMINISTRADOR`, `OPERADOR`, `AUDITOR` |
| `entidade_convites` | convite pendente por e-mail ou senha temporária | papel na entidade e, opcionalmente, no gabinete |

Os vínculos têm `ativo`, `ingressou_em`, `desativado_em` e `criado_por`. Remover alguém de um gabinete desativa o vínculo, sem apagar nada.

### Como a autorização usa isso

As rotas são `/entidades/{entidade}/gabinetes/{gabinete}/...`. O middleware `ResolveEntityContext` confere se há vínculo ativo **com cargo** e registra o gabinete da URL no `OfficeContext`, sem gravar nada na conta. Policies, controllers e Form Requests leem dali o gabinete (`officeIdFor()`) e o cargo do vínculo com esse gabinete (`positionFor()`), e decidem por `OfficeAccess` (ver "Cargos locais" abaixo). Desde 04/11/2026 o papel do Hub não é lido. Cargo `AUDITOR` no gabinete da URL recebe 403 em qualquer escrita dentro do contexto. O isolamento de dados usa o mesmo `OfficeContext`. Detalhes em [ARCHITECTURE.md](ARCHITECTURE.md#gabinete-e-cargo-da-requisicao).

A entrada após o login (`/dashboard`) usa `users.gabinete_id` enquanto a pessoa tiver acesso a esse gabinete; senão, o vínculo ativo mais antigo; sem nenhum, o diretório de entidades (`RedirectLegacyTenantRoute`).

### Por onde as contas nascem hoje

| Origem | Conta criada com | Vínculos |
|---|---|---|
| ~~Administração → Gabinetes (responsável)~~ | — | Desligado em 24/09/2026: gabinete nasce no Hub, sem conta; o acesso vem dos vínculos. |
| Equipe do gabinete | `role` administrador/operador/auditor, `gabinete_id` | gabinete com o papel escolhido; entidade com o mesmo papel (administrador só em gabinete independente) |
| Convite da entidade | `role` derivado do papel convidado; `gabinete_id` pode ficar vazio | os do convite |
| Administração → Usuários root | `role = root` | nenhum |

### Problemas conhecidos, que ficam para o Hub

- `gabinete_membros.papel` e `users.role` descrevem a mesma coisa; o mapeamento entre eles está repetido no `OfficeContext::roleFor()`, no serviço de convites e no `syncLegacyUser`.
- ~~`GESTOR` existe nos dois enums com significados diferentes; `AUDITOR` pode ser concedido, mas nenhuma regra o diferencia de `OPERADOR`.~~ Resolvido em 23/09/2026: um só enum (`AccessRole`) e auditor somente leitura.
- `users.role` não acompanha promoções: fora de uma requisição com contexto (filas, comandos, notificações), vale o papel da criação.
- Existem duas noções de inativo (`users.is_active` e `gabinete_membros.ativo`) e nenhuma tela mostra as duas juntas.
- Senhas são redefinidas por `window.prompt` na Equipe e na administração; `exige_troca_senha` só existe nos convites.

## O que o GEP continua precisando localmente

A tabela `users` não pode desaparecer: ela é chave estrangeira de dados operacionais e de auditoria. Alguns exemplos:

- `criado_por_id` em demandas, compromissos, atendimentos e eventos (`restrictOnDelete`);
- responsáveis e atendentes de demandas, agenda, atendimentos e eventos (`nullOnDelete`);
- autores de encaminhamentos, atualizações, favoritos, convites, transferências e eventos de contexto;
- `gabinete_membros`, `entidade_membros`, `compromisso_participantes` e `whatsapp_contatos` (`cascadeOnDelete`).

Por isso, com o Hub, o GEP mantém uma **cópia local** de cada pessoa e dos seus vínculos, alimentada pelo Hub, e **nunca exclui fisicamente** uma conta: desativa.

O GEP também precisa listar pessoas que ainda não entraram no sistema — por exemplo, para escolher o responsável de uma demanda ou os participantes de um compromisso.

## Proposta

1. **Fonte da verdade:** o Hub cria, altera e desativa contas e vínculos. As telas do GEP que fazem isso (Equipe, convites, responsável do gabinete, usuários root) passam a ser somente leitura ou apontam para o Hub.
2. **Login:** SSO pelo Hub via OpenID Connect. O GEP deixa de guardar senha, 2FA e passkeys e confia na autenticação do Hub.
3. **Identificador:** nova coluna `users.hub_user_id` (única), preenchida com o `sub` do Hub. O casamento é sempre por esse identificador; o e-mail é só um dado da pessoa.
4. **Provisionamento:** combinação de dois caminhos.
   - **No login**, o GEP cria ou atualiza a pessoa e os vínculos a partir das claims recebidas.
   - **Por webhook assinado**, o Hub avisa criação, alteração e desativação de pessoas e vínculos, para o GEP conseguir listar quem ainda não entrou e bloquear quem foi desligado sem esperar o próximo login.
5. **Papéis:** o Hub envia os papéis já no vocabulário do GEP (`entidade_membros.papel` e `gabinete_membros.papel`), ou um vocabulário próprio com mapeamento documentado aqui. `users.role` passa a ser derivado e deixa de ser editado.
6. **Desativação:** desligar alguém no Hub desativa a conta local (`is_active = false`) e encerra as sessões ativas no GEP.

## Decisões fechadas (22/09/2026)

| # | Pergunta | Decisão | Observação |
|---|---|---|---|
| 1 | Protocolo de login | **OIDC** | ✅ Implementado no Hub em 23/09/2026. O Hub virou Identity Provider com `laravel/passport` (OAuth2 Authorization Code + PKCE) e uma camada fina própria para o que falta de OIDC — `id_token`, descoberta, JWKS e `userinfo`. Não existe pacote maduro de *provider* OIDC para Laravel 13; só de cliente. No GEP, o consumo será por Socialite com provider genérico apontado ao documento de descoberta. Detalhes no passo 2 do plano. |
| 2 | Período de transição | **Corte direto** | Sem login local e SSO lado a lado. Login local do GEP é desativado no dia do corte — exige que a carga inicial (decisão #7) e a base técnica (webhook, `hub_user_id`) estejam prontas e testadas antes do corte. |
| 3 | Provisionamento | **Login + webhook** | No login, o GEP sincroniza a própria conta; webhook assinado do Hub avisa mudanças em tempo real (necessário para listar quem ainda não entrou e bloquear desligados sem esperar login). |
| 4 | Contrato do webhook | **Eventos por pessoa e por vínculo** | Não é snapshot periódico. Precisa de assinatura, idempotência e fila de reprocessamento por evento perdido. |
| 5 | Vocabulário de papéis | **Fechado (23/09/2026): `administrador` · `operador` · `auditor`, mais `root` local** | Padrão do ecossistema, igual em todos os produtos — ver "Vocabulário de papéis" abaixo. |
| 6 | Usuários root | **Mantidos locais no GEP** | Preserva acesso de emergência se o Hub cair — coerente com a decisão #10. |
| 7 | Contas já existentes | **Vínculo único por e-mail** | Cada e-mail do GEP casa com uma pessoa no Hub na carga inicial. E-mails duplicados ou divergentes precisam de revisão manual antes do corte. |
| 8 | 2FA e passkeys atuais | **Hub precisa oferecer o equivalente antes do corte** | O corte só acontece quando o Hub já tiver 2FA/passkey funcionando — não pode reduzir a segurança das contas na virada. Bloqueia a decisão #2 até estar pronto. |
| 9 | WhatsApp e consentimento | **Ficam no GEP** | Continuam dado operacional do GEP; fora do escopo de identidade/acesso do Hub. |
| 10 | Queda do Hub | **Aceita sessão já aberta até expirar** | Só login novo é bloqueado quando o Hub está fora do ar; quem já estava logado continua trabalhando até a sessão expirar. Root local (#6) cobre o acesso administrativo de emergência. |

## Plano sugerido

1. ~~**Contrato:** fechar as decisões acima e o formato das claims e dos webhooks.~~ ✅ Decisões fechadas em 22/09/2026 (falta ainda o formato exato das claims OIDC e do payload dos webhooks, item de detalhe técnico do passo 2).
2. **Base:** ✅ **concluído em 23/09/2026 dos dois lados** — Hub como provedor, GEP como primeiro consumidor. O contrato efetivo do lado do GEP está em "O que o GEP implementou", no fim deste passo; é o que GRI e GPC replicam.

   O que o Hub passou a oferecer:

   **Endpoints OIDC** — `issuer` é a `APP_URL` do Hub, sem barra final.

   | Endpoint | Para quê |
   |---|---|
   | `GET /.well-known/openid-configuration` | Descoberta. É o único endereço que o GEP precisa configurar. |
   | `GET /oauth/authorize` | Código de autorização. Exige `code_challenge_method=S256`; `response_type` só aceita `code`. |
   | `POST /oauth/token` | Troca do código pelo par de tokens. A resposta traz `id_token` quando o pedido incluiu o escopo `openid`. |
   | `GET /oauth/jwks` | Chave pública RSA (RS256) que valida o `id_token`. O `kid` é o thumbprint RFC 7638 da própria chave. |
   | `GET\|POST /oauth/userinfo` | Perfil do portador do access token. |

   Escopos: `openid`, `profile`, `email`. Cliente OAuth por sistema, criado com
   `php artisan hub:registrar-cliente-oidc GEP --redirect=<uri>`; o id fica em
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
   `users.hub_user_id` no GEP (decisão #3). Vínculo e papel não viajam em claim:
   mudam mais rápido do que um token vive, e quem precisa deles chama
   `GET /api/v1/sistemas/{codigo}/pessoas/{usuario}`, que responde o estado de
   agora. O `/oauth/userinfo` devolve as mesmas claims de perfil mais `sub`.
   Não há claim `nonce` — se o GEP usar `nonce`, ele não volta no token.

   **Webhook assinado** (decisão #4) — `POST` no `sistemas.webhook_url`, com
   `X-Hub-Timestamp`, `X-Hub-Nonce` e `X-Hub-Signature: sha256=<hex>`. O HMAC-SHA256
   usa `sistemas.client_secret` sobre o canônico
   `MÉTODO\nCAMINHO?QUERY\nTIMESTAMP\nNONCE\nsha256(corpo)` — o mesmo esquema que o
   GEP já valida em `WhatsAppCallbackSignatureValidator`. Tipos de evento:
   `pessoa.criada`, `pessoa.alterada`, `pessoa.desligada`, `vinculo.criado`,
   `vinculo.alterado`, `vinculo.encerrado` e, desde 24/09/2026, os de estrutura
   `entidade.criada|alterada|removida` e `unidade.criada|alterada|removida`
   (ver "Estrutura definida pelo Hub" abaixo).

   ```json
   {
     "id": "<uuid do evento>",
     "tipo": "vinculo.alterado",
     "sistema": "GEP",
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

   É retrato, não diferença: o GEP aplica o bloco recebido por cima do que tem e
   fica correto mesmo se um aviso anterior nunca chegou. `id` é o que o GEP usa
   para descartar reentrega. Eventos de pessoa trazem só `dados.pessoa`. Os avisos
   ficam numa caixa de saída (`webhook_eventos`) e o que falha é reenfileirado por
   `hub:reprocessar-webhooks-pendentes`, agendado a cada cinco minutos.

   **2FA e passkeys** (decisão #8) — `laravel/fortify` com `laravel/passkeys`, a
   mesma dupla que o GEP já usa, com 2FA confirmado (`confirm: true`) e
   confirmação de senha para gerenciar os dois. Tela em `/settings/security`.

   **API de leitura** — `/api/v1/*` estava aberta e passou a exigir
   `Authorization: Bearer <client_secret do sistema>`. O mesmo segredo assina os
   webhooks; o segredo do handshake OIDC é outro, e mora no Passport.

   **Vocabulário de papéis** (decisão #5) — fechado em 23/09/2026; ver a seção
   "Vocabulário de papéis" abaixo.

   ### O que o GEP implementou (contrato efetivo — referência para GRI e GPC)

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

   **Contingência (04/11/2026, PLATFORM.md §4).** O acesso local do root
   (senha + 2FA, ou passkey) passou a depender de `GEP_ROOT_LOGIN_ENABLED`
   (`config/govnexgep.php`, padrão `false`; regra em `App\Auth\LocalRootLogin`):

   | Hub configurado (`HUB_BASE_URL` + `HUB_OIDC_CLIENT_ID` + `HUB_OIDC_CLIENT_SECRET`) | Flag | Acesso local do root |
   |---|---|---|
   | não | qualquer | **liga** — ambiente novo não tem outra porta de entrada |
   | sim | `false` (padrão do código) | **desligado**: o botão "Acesso local de emergência" some e o `POST /login` é recusado com a mensagem genérica de credenciais (não revela se a conta existe); passkey também recusa |
   | sim | `true` (**produção**, ver abaixo) | liga, só para root ativo |

   **Decisão do proprietário (04/11/2026): a equipe GOVNEX faz todos os
   cadastros e administra o GEP pelo root local, sem SSO — no dia a dia e em
   emergência.** Por isso, em produção, `GEP_ROOT_LOGIN_ENABLED=true` (ver
   `.env.production.example`); o padrão do código continua `false` (ambiente
   sem a variável fica fechado). **Recomendado, pendente:** exigir 2FA ou
   passkey em toda conta root de produção (hoje é opcional, em
   `/settings/security`).

   - Não-root nunca entra por senha nem por passkey.
   - Limite: 5 tentativas por minuto por e-mail + IP (limiter `login` do
     Fortify); a 6ª recebe 429.
   - Auditoria reforçada: toda tentativa contra conta root grava
     `ROOT_LOGIN_LOCAL` em `contexto_acesso_eventos` (`resultado`
     `PERMITIDO`/`NEGADO`, `administrador_plataforma = true`, `contexto` com
     `contingencia: true`, `motivo` — `sucesso`, `credenciais_invalidas`,
     `bloqueado_por_tentativas`, `desligado_por_flag`, `conta_inativa` —,
     `metodo`, estado da flag e do Hub) e um `Log::warning`. A senha nunca é
     registrada; IP e user agent só como HMAC.
   - **Ligar/desligar:** no servidor, ajuste `GEP_ROOT_LOGIN_ENABLED` e rode
     `php artisan config:cache` (ou reinicie os containers que leem o
     `.env`). Em produção ela fica `true`; desligue só se a decisão mudar
     (ex.: root deixar de ser o caminho de administração). Confira as
     tentativas em `contexto_acesso_eventos` (`evento = 'ROOT_LOGIN_LOCAL'`).

   **Variáveis de ambiente** (ver `.env.example` e `config/services.php`, chave
   `services.hub`). Sem `HUB_BASE_URL` + `HUB_OIDC_CLIENT_ID` +
   `HUB_OIDC_CLIENT_SECRET` preenchidos, a tela de login nem oferece o botão do
   Hub — ambiente novo continua entrando pelo acesso local até o SSO ser
   configurado.

   | Variável | Origem | Papel |
   |---|---|---|
   | `HUB_BASE_URL` | endereço do Hub | Base da descoberta OIDC e da API. |
   | `HUB_SISTEMA_CODIGO` | fixo, `GEP` | Código do `Sistema` no Hub; entra na URL da API e é conferido no campo `sistema` do webhook. |
   | `HUB_OIDC_CLIENT_ID` / `HUB_OIDC_CLIENT_SECRET` | `php artisan hub:registrar-cliente-oidc GEP --redirect=<HUB_OIDC_REDIRECT>`, rodado **no Hub** | Handshake OIDC. |
   | `HUB_OIDC_REDIRECT` | `${APP_URL}/auth/hub/callback` | Precisa bater exatamente com o `--redirect` registrado. |
   | `HUB_API_SECRET` | `sistemas.client_secret` do Sistema GEP no Hub | Duplo papel: `Bearer` na leitura de `/api/v1/*` e chave HMAC que valida o webhook. |
   | `HUB_TIMEOUT` | opcional, 15 | Timeout das chamadas ao Hub. |
   | `HUB_WEBHOOK_WINDOW_SECONDS` | opcional, 300 | Tolerância do `X-Hub-Timestamp`. |
   | `HUB_WEBHOOK_MAX_ATTEMPTS` | opcional, 5 | Teto de tentativas de um evento com falha nossa. |
   | `HUB_WEBHOOK_RETENTION_DAYS` | opcional, 30 | Retenção dos eventos `PROCESSADO`. |
   | `HUB_WEBHOOK_FAILED_RETENTION_DAYS` | opcional, 90 | Retenção dos eventos `FALHA_PERMANENTE`. |

   **Peças.** `app/Services/Hub/`: `HubSocialiteProvider` (driver `hub` do
   Socialite, registrado em `AppServiceProvider`; sem `nonce`, perfil pelo
   `userinfo`), `GovnexHubApiClient` (leitura de
   `/api/v1/sistemas/{codigo}/pessoas/{usuario}`), `HubProvisioningService`
   (casa a conta por `hub_user_id` e, na falta dele, por e-mail — a carga
   inicial da decisão #7 acontecendo no primeiro acesso —, ou cria),
   `HubVinculoSyncService` (escreve `entidade_membros`/`gabinete_membros` e
   reprojeta `users.gabinete_id`; desde 04/11/2026 ignora o `papel` — o
   `HubPapelMapper` foi removido), `HubCallbackSignatureValidator`
   e `HubEventProcessor`. Controllers: `Auth/HubAuthController` e
   `Api/HubCallbackController`. Colunas de ponte: `users.hub_user_id` (já
   existia), `entidades.hub_entidade_id` e `gabinetes.hub_unidade_id`.

   **Caixa de entrada do webhook (04/11/2026, PLATFORM.md §5).** A
   idempotência deixou de ser `Cache::add` e passou para a tabela
   `hub_webhook_eventos` (`App\Models\HubWebhookEvent`, lógica em
   `HubWebhookInbox`), com `evento_id` (o `id` do Hub) **único**. A assinatura e
   o formato continuam sendo conferidos antes — nada é gravado com assinatura
   ou payload inválidos (401/422). O processamento continua síncrono pelo
   `HubEventProcessor`, e o contrato com o Hub não mudou:

   | Situação | Status na tabela | Resposta |
   |---|---|---|
   | Processado (inclui `*_ignorada`, com o motivo em `resultado`) | `PROCESSADO` | 200 com o resultado |
   | Payload inaplicável (`RuntimeException`) | `FALHA_PERMANENTE` | 409 |
   | Falha nossa | `FALHA_TRANSITORIA` (backoff `min(2^tentativas, 60)` min) ou `FALHA_PERMANENTE` ao atingir o teto | 500 |
   | Reentrega de `PROCESSADO`/`FALHA_PERMANENTE`, ou com outra entrega processando | inalterado | 200 `{ok, duplicado: true}` |
   | Reentrega de `FALHA_TRANSITORIA`/`RECEBIDO` | reprocessa (`tentativas + 1`) | conforme o resultado |

   Concorrência: a gravação é `INSERT` com ignore no UNIQUE, e a retomada de
   uma linha existente é um `UPDATE` condicional ao status — duas entregas do
   mesmo `id` nunca processam juntas. `ultimo_erro` guarda a mensagem do 409
   (a mesma devolvida ao Hub) ou só a classe da exceção no 500; o detalhe vai
   para o `report()`. O `payload` (evento validado, com dados pessoais) é
   guardado para o reprocesso e nunca é logado.

   - `php artisan hub:reprocessar-webhooks` (agendado a cada 5 minutos,
     `withoutOverlapping`): reprocessa `FALHA_TRANSITORIA` com a próxima
     tentativa vencida, `RECEBIDO` esquecido há mais de 5 minutos e
     `PROCESSANDO` preso há mais de 15, sempre abaixo do teto. `--id=<uuid>`
     reprocessa um evento específico, inclusive `FALHA_PERMANENTE`;
     `--dry-run` só lista.
   - `php artisan hub:limpar-webhooks` (diário, 03:50): apaga `PROCESSADO` com
     mais de `HUB_WEBHOOK_RETENTION_DAYS` e `FALHA_PERMANENTE` parados há mais
     de `HUB_WEBHOOK_FAILED_RETENTION_DAYS`; pendentes e falhas temporárias
     ficam. Depois da limpeza, uma reentrega do mesmo `id` seria processada de
     novo — o Hub não reenvia avisos tão antigos, e o retrato é idempotente.
   - Reprocessar um aviso antigo pode reaplicar um retrato mais velho que o
     atual (eventos de estrutura já descartam por `atualizado_em`; de pessoa e
     vínculo, não). Use `--id` com isso em mente.

   **Duas regras que valem para quem replicar.** Retrato, não diferença: o login
   aplica a lista completa e desativa o que não veio; o webhook mexe só no
   vínculo citado, porque um aviso isolado não afirma nada sobre os outros.
   E nada apaga linha — vínculo encerrado vira `ativo = false` com
   `desativado_em`, que é o histórico de auditoria do GEP, não do Hub.

   **Espelhamento da estrutura (antes do primeiro login SSO).** Rode
   `php artisan hub:espelhar-estrutura --dry-run` e, se o relatório estiver
   coerente, `php artisan hub:espelhar-estrutura`. O comando lê a estrutura pela
   API do Hub, casa entidade por `slug` e gabinete (dentro da entidade) por
   `slug` — a mesma regra do `ImportadorDoGep` no Hub — e preenche
   `hub_entidade_id`/`hub_unidade_id` só onde estão nulos; é idempotente e nunca
   sobrescreve um id diferente (reporta conflito e sai com falha). Sem esse
   passo, vínculos não mapeáveis são ignorados com aviso em log e o login
   **não desativa** os vínculos locais existentes (só uma lista vazia
   deliberada, ou `pessoa.desligada`, desliga tudo).

   ### Estrutura definida pelo Hub (24/09/2026)

   O Hub é a fonte da verdade do **nome e da situação** das entidades e
   unidades **já ligadas** (`entidades.hub_entidade_id`,
   `gabinetes.hub_unidade_id`). Renomear, suspender ou reativar no Hub chega ao
   GEP pelo webhook. Todo o resto — slug, tipo, cores, módulos, licença,
   timezone — continua do GEP.

   **Eventos.** `entidade.criada`, `entidade.alterada`, `entidade.removida`,
   `unidade.criada`, `unidade.alterada`, `unidade.removida`. Desde a fase 2
   (abaixo) o Hub os emite só para os sistemas notificáveis **habilitados na
   entidade** (para unidade, na entidade dela), e só quando muda campo
   relevante (`nome, slug, sigla, tipo, municipio, estado, status` na
   entidade; `nome, slug, tipo, ativa, unidade_pai_id` na unidade) ou a
   exclusão.

   ```json
   {
     "id": "<uuid>", "tipo": "entidade.alterada", "sistema": "GEP",
     "ocorrido_em": "2026-09-24T10:00:00-03:00",
     "dados": {
       "entidade": {
         "id": "3", "conta_id": "1", "nome": "Gabinete Santos",
         "slug": "gabinete-santos", "sigla": null, "tipo": "CAMARA_MUNICIPAL",
         "status": "ativa", "municipio": "Fortaleza", "estado": "CE",
         "timezone": "America/Fortaleza",
         "atualizado_em": "2026-09-24T10:00:00-03:00"
       }
     }
   }
   ```

   Eventos de unidade trazem `dados.unidade` =
   `{id, entidade_id, unidade_pai_id, nome, slug, sigla, codigo, tipo,
   entidade_tipo, ativa, atualizado_em}` (`sigla`, `timezone`, `codigo` e
   `entidade_tipo` desde a fase 2).
   Ids são string; `atualizado_em` é o `updated_at` do registro no Hub. Não há
   bloco `pessoa`.

   **Como o GEP aplica** (`HubEstruturaSyncService`, chamado pelo
   `HubEventProcessor` antes da exigência de `pessoa`):

   | Situação | Resultado |
   |---|---|
   | `*.alterada` de item ligado | Aplica `nome` e situação: entidade `status = ativa` → `ATIVA`, qualquer outro → `SUSPENSA` (com `suspensa_em`); unidade `ativa` → gabinete `ativo`/`suspenso` (com `suspended_at`). Slug **nunca** é alterado. |
   | `*.removida` de item ligado | **Suspende**, nunca apaga (reversível; os dados operacionais ficam). |
   | Item não ligado | `Log::info` e **200** (`entidade_ignorada`/`unidade_ignorada`). Nunca 409/422. |
   | `*.criada` | Cria a entidade/o gabinete — ver "Estrutura criada no Hub nasce no GEP". Item já ligado cai na linha de `*.alterada`. |
   | `atualizado_em` mais antigo que `hub_sincronizado_em` | Descartado (`evento_antigo_descartado`). Igual é reaplicado — `updated_at` tem resolução de segundo e o retrato é idempotente. |
   | Bloco `entidade`/`unidade` ausente ou sem `id` | 409 (payload inaplicável), como vínculo sem bloco. |

   `entidades.hub_sincronizado_em` e `gabinetes.hub_sincronizado_em` guardam o
   `atualizado_em` do último retrato aplicado.

   **Edição local bloqueada.** Para item ligado, nome (Administração →
   Gabinetes, Configurações do gabinete, identidade da entidade) e situação do
   gabinete (suspender/reativar na administração) ficam desabilitados com a dica
   "Definido no Govnex Hub — altere lá", e o backend recusa valor diferente do
   atual (`App\Rules\DefinidoNoHub`). A edição do gabinete independente deixa de
   copiar nome e situação para a entidade quando ela está ligada. Itens não
   ligados continuam editáveis.

   **Rede de segurança.** `php artisan hub:espelhar-estrutura --atualizar`
   (com `--dry-run` para só listar divergências) aplica nome e situação do Hub
   aos itens ligados, pedindo à API inclusive os suspensos
   (`?somente_ativas=false`). Cobre aviso perdido ou expirado e divergências
   antigas. O comando casa item já ligado pelo id do Hub; o slug só é usado para
   ligar pela primeira vez. A API do Hub passou a devolver `status` e
   `atualizado_em` nas entidades e `atualizado_em` nas unidades (aditivo).

   **Ordem de deploy: GEP antes do Hub.** O GEP antigo responde 422 a tipo
   desconhecido e o Hub trata 4xx como falha permanente: se o Hub subir antes,
   os eventos de estrutura ficam `Falhou`. Depois de publicar os dois, rode
   `hub:espelhar-estrutura --atualizar --dry-run` e, se coerente, sem
   `--dry-run`.

   **Fora de escopo da fase 1.** Conta (o GEP não a conhece), alteração de
   timezone e tipo depois de criado, e unidades aninhadas (o GEP é plano). A
   criação ficou para a fase 2, logo abaixo.

   ### Estrutura criada no Hub nasce no GEP (fase 2, 24/09/2026)

   O Hub é o **único ponto de criação** de entidade e gabinete. O que ele cria
   para o GEP nasce aqui sozinho, sem inventar dado: titular
   (`vereador_nome`), número eleitoral, cores e protocolo continuam sendo
   preenchidos no GEP depois (Configurações do gabinete / Administração →
   Gabinetes).

   **Habilitação por entidade (Hub).** Cada entidade declara no `/estrutura`
   do Hub os **sistemas habilitados** (tabela `entidade_sistema`; só
   administrador do Hub edita). Os avisos de estrutura da entidade e das
   unidades dela vão só para os habilitados. Habilitar o GEP numa entidade —
   inclusive ao criá-la — manda ao GEP, e só a ele, `entidade.criada` e
   `unidade.criada` de tudo o que já existe (pai antes do filho); desabilitar
   só para de avisar (nada é suspenso aqui). Com o GEP habilitado, a entidade
   precisa ter município e UF (próprios ou da organização). A migration do Hub
   habilita, para cada entidade, os sistemas em que ela já tem vínculo; o
   importador habilita o GEP no que veio do GEP — nenhum dos dois anuncia
   `*.criada`.

   **Payload (aditivo).** `dados.entidade` ganhou `sigla`, `timezone` (da
   conta) e `municipio`/`estado` **resolvidos** (da entidade, senão da conta).
   `dados.unidade` ganhou `sigla`, `codigo` e `entidade_tipo`.

   **Endpoint novo.** `GET /api/v1/entidades/{id}` (mesma autenticação
   `Bearer`): os campos da listagem mais `tipo` no topo, município/UF
   resolvidos, `timezone`, `sistemas_habilitados` (códigos) e `habilitado`
   (o sistema que chama está entre eles). 404 para entidade inexistente ou
   removida. Unidade avulsa continua em `GET /api/v1/unidades/{id}` (traz
   `unidade_pai_id` e `tipo`).

   **Tipo "Gabinete independente" no Hub.** `EntidadeTipo::GabineteIndependente`
   (`GABINETE_INDEPENDENTE`, natureza pública, sem poder/esfera obrigatórios).
   O importador passou a classificá-lo assim; as entidades que importações
   antigas trouxeram como `OUTRA` são revisadas com
   `hub:reclassificar-gabinetes-independentes --dry-run` (no Hub), que só
   aplica — com confirmação ou `--force` — às candidatas com uma única
   unidade `GABINETE` e origem no GEP.

   **Como o GEP cria** (`HubEstruturaSyncService::criarEntidade`/`criarUnidade`,
   sobre `EstruturaProvisioningService`, o mesmo caminho da antiga criação
   manual):

   | Situação | Resultado |
   |---|---|
   | Entidade `CAMARA_MUNICIPAL`, `PREFEITURA` ou `GABINETE_INDEPENDENTE` | Cria com `municipio`/`estado`/`timezone` do payload, slug **derivado do nome** e globalmente único (nunca o do Hub), situação do Hub, `interface_simplificada` só no independente, `hub_entidade_id` + `hub_sincronizado_em`, licença `LEGADO_COMPLETO` e todos os módulos da entidade. |
   | Unidade raiz com tipo derivável | Cria o gabinete com município/UF/fuso herdados da entidade local, slug do nome, todos os módulos do catálogo (evento de módulo com `origem = GOVNEX_HUB`, sem administrador), município eleitoral ligado se existir, `vereador_nome`/`numero_eleitoral` nulos e **nenhum usuário**. |
   | Tipo do gabinete | Derivado de entidade + unidade (`HubTipoMapper`). Câmara: `GABINETE` → gabinete parlamentar; estrutura administrativa e áreas-meio (`SECRETARIA`, `DIRETORIA`, `DEPARTAMENTO`, `SETOR`, `ASSESSORIA`, `RECURSOS_HUMANOS`…) → setor administrativo. Prefeitura: `GABINETE` → gabinete do prefeito, `SECRETARIA` → secretaria, demais administrativas → setor administrativo. Independente: `GABINETE` → gabinete independente. |
   | Tipo sem equivalente (entidade `AUTARQUIA`…, unidade `ESCOLA`, `HOSPITAL`, `OUTRA`…), unidade aninhada, segundo gabinete de independente, dados incompletos | `Log::info` e **200** com `acao = *_ignorada` e `motivo`. Nunca 409/422. |
   | Item local **não ligado** com o mesmo slug que o Hub informou | Ignorado (`casamento_pendente`): é o mesmo item vindo do importador; ligar é tarefa do `hub:espelhar-estrutura`, não do webhook. |
   | Reentrega / item já ligado | Cai na atualização (nome e situação). O `unique` de `hub_entidade_id`/`hub_unidade_id` segura criação simultânea. |
   | `unidade.criada` antes da entidade | Resolve a entidade **sob demanda** (`GET /api/v1/entidades/{id}`) respeitando `habilitado`; se o Hub não responder, devolve **500** para o Hub reenviar. |

   **Vínculo sob demanda.** `HubVinculoSyncService` (login e webhook), antes
   de escrever, cria pela API a entidade/unidade do vínculo que ainda não está
   espelhada (`GET /api/v1/entidades/{id}` e `/unidades/{id}`). Só descarta o
   vínculo se o Hub disser que o GEP não está habilitado, o tipo não tiver
   equivalente ou o Hub não responder — e aí vale a regra de antes: o login
   não desativa os ausentes.

   **Rede de segurança.** `php artisan hub:espelhar-estrutura --criar` cria o
   que falta (entidade com o GEP habilitado e tipo com equivalente, unidades
   raiz com tipo com equivalente); `--criar --dry-run` só lista o que
   nasceria e o motivo de cada item que não nasce.

   **Criação local bloqueada.** `admin.entities.create` e
   `admin.offices.create` redirecionam para Administração → Gabinetes com
   "A estrutura é criada no Govnex Hub"; `admin.entities.store` e
   `admin.offices.store` respondem 403. Os botões "Nova entidade"/"Novo
   gabinete" viraram atalhos para `{HUB_BASE_URL}/estrutura` (sem
   `HUB_BASE_URL`, só o aviso). A edição de gabinete na administração não cria
   nem altera mais a conta do responsável — ela é só exibida. Continuam
   editáveis no GEP os campos de domínio dele (titular, número eleitoral,
   contato, endereço, módulos, cores…).

   **Ordem de deploy: GEP antes do Hub.** O Hub só passa a emitir `*.criada`
   filtrado por habilitação depois do `migrate` (tabela `entidade_sistema`),
   que exige autorização. Depois de publicar os dois: habilitar o GEP numa
   entidade de teste no Hub, criar uma unidade e conferir o GEP; rodar
   `hub:espelhar-estrutura --criar --dry-run` para ver o que falta.
3. **Carga inicial:** ✅ acontece automaticamente — `HubProvisioningService` casa a conta por `hub_user_id` e, na falta dele, por e-mail (decisão #7), no primeiro login de cada pessoa. Falta revisar divergências (duplicados, e-mails que não batem) antes do corte em produção.
4. **Corte:** ✅ **concluído em 24/09/2026 no que depende só de código.** Login local do GEP já recusa quem não é `root` desde a fase 2 (decisão #2); SSO pelo Hub é o caminho normal. Telas de gestão de usuários do GEP passaram a somente leitura: `TeamController`/`UserPolicy` (equipe do gabinete) e `EntidadeInvitationController::store` (convite novo) retornam 403 com "Gerenciado no Govnex Hub — altere lá" (`HubManagedHint`); convites pendentes anteriores ao corte ainda podem ser aceitos (`EntidadeInvitationAcceptController`, não tocado). Sessões já abertas continuam até expirar se o Hub cair (decisão #10); root continua local (decisão #6), fora desse corte. Falta só a decisão de produção sobre desligar senha/2FA/passkeys locais dos não-root (passo 5).
5. **Limpeza:** remover senha, 2FA e passkeys locais do GEP e `users.role` como dado editável. ✅ Em 28/10/2026 saiu a projeção do contexto em `users.gabinete_id`/`users.role` durante a requisição (`ResolveEntityContext`): Policies, controllers, Form Requests e middlewares passaram a ler gabinete e papel do `OfficeContext`. As rotas sem prefixo continuam, de propósito: `RedirectLegacyTenantRoute` é a entrada após o login e trata links antigos, e lê `users.gabinete_id` como gabinete de origem da conta (assim como `EnsureOfficeIsActive` nessas rotas e o gabinete padrão do `HandleInertiaRequests` em telas sem gabinete). As colunas ficam até os consumidores fora de requisição (filas, comandos, notificações) consultarem os vínculos.

## Vocabulário de papéis (decisão #5, fechada em 23/09/2026 — superada em 04/11/2026)

> **Superada:** o Hub não tem mais papéis; o GEP ignora o `papel` recebido e decide pelo cargo local (ver "Cargos locais"). O texto abaixo é histórico.

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
- No GEP, o `HubPapelMapper` aceita só `administrador`/`operador`/`auditor`
  (sem distinção de caixa) e grava `AccessRole` (`ADMINISTRADOR`/`OPERADOR`/`AUDITOR`)
  em `gabinete_membros.papel` e `entidade_membros.papel`. Papel desconhecido vira
  **auditor** (menor privilégio) com aviso em log; vínculo com papel `root` é
  ignorado. Administrador de uma unidade só é administrador da **entidade**
  quando ela é gabinete independente; em Câmara ou Prefeitura vira operador da
  entidade.
- `users.role` é projeção 1:1 do papel do vínculo principal (`administrador`,
  `operador`, `auditor`) ou `root`. Dentro de uma requisição com contexto a autorização não
  lê mais essa coluna (ver
  [ARCHITECTURE.md](ARCHITECTURE.md#gabinete-e-papel-da-requisicao)); ela ainda
  vale fora de requisição.

Mapeamento para os papéis antigos:

| Produto | Papel antigo | Papel no ecossistema |
|---|---|---|
| GEP | vereador, chefe de gabinete (`LIDER`, `GESTOR`) | administrador |
| GEP | assessor (`MEMBRO`) | operador |
| GRI (quando integrado) | `administrator` | administrador |
| GRI (quando integrado) | `executive` | auditor |
| GRI (quando integrado) | `risk_management`, `unit_manager`, `risk_owner`, `collaborator` | operador (as funções viram atribuições no GRI) |
| GPC (quando integrado) | `administrator` | administrador |
| GPC (quando integrado) | `analyst` | operador; auditor para revisores só de leitura |

## Cargos locais

**Decisão (04/11/2026): o Hub é só vínculo e autenticação. Não há papéis do
Hub; cada sistema controla os seus.** O GEP deixou de depender do papel do Hub
por completo: o campo `papel` ainda chega no login e no webhook (o Hub ainda o
envia), mas é **ignorado** — vínculo ativo significa só "está vinculado". Não
há mais pontes de transição.

- **Cargo = local do GEP**, em `gabinete_membros.cargo`
  (`App\Enums\OfficePosition`: `VEREADOR`, `CHEFE_GABINETE`, `SECRETARIO`,
  `ASSESSOR`, `AUDITOR`). O Hub não o envia, e o `HubVinculoSyncService`
  nunca o escreve nem o limpa — nem ao encerrar e reativar o vínculo.
- **Cargo por tipo de gabinete** (`OfficePosition::allowedFor()`, único mapa,
  validado no backend): gabinete parlamentar e gabinete independente →
  vereador, chefe de gabinete, assessor; Secretaria legislativa → secretário,
  assessor; `AUDITOR` em **todos** os tipos (único cargo dos demais, por ora).
- **Decisão num ponto só**: `App\Authorization\OfficeAccess::can($user, OfficeCapability)`,
  só pelo cargo. As Policies e os `canDelete` dos controllers consultam
  capacidades nomeadas (`writeRecords`, `deleteRecords`, `reassignDemands`,
  `exportReports`, `configureOffice`, `manageTeam` e três do módulo Secretaria
  legislativa). A interface recebe o mesmo mapa em `auth.permissions`; em
  `auth.user.role` só vai a marca `root`/`membro`.
- **Matriz.** Root: poder total em qualquer gabinete. Vereador e chefe de
  gabinete: tudo, iguais (inclusive Equipe e atribuição de cargos). Assessor:
  lê tudo, cria e altera; não exclui, não redistribui demandas (trocar
  responsável já definido; atribuir o primeiro pode), não abre Relatórios,
  não configura, não vê a Equipe. Secretário: só o módulo Secretaria
  legislativa; fora dele só lê (**pendente**). Auditor: somente leitura — o
  `ResolveEntityContext` recusa escrita e as Policies negam.
- **Falha fechada sem cargo.** Vínculo ativo sem cargo não tem capacidade
  nenhuma e **não entra no gabinete** (`User::canAccessGabinete()` exige
  cargo): a entrada pós-login cai no diretório da entidade, como quem não tem
  gabinete, e a rota do gabinete responde 403. A Equipe mostra "Sem cargo" e
  orienta a pedir ao vereador/chefe de gabinete ou ao operador do sistema.
- **Gestão da entidade** (`User::canManageEntidade()`: transferências,
  módulos, convites legados): root ou, em gabinete independente, quem lidera o
  gabinete dela pelo cargo. Câmara e Prefeitura ainda não têm cargo de
  entidade — só root (**pendente**).
- **Unicidade (decisão do proprietário, 04/11/2026):** no máximo **1
  vereador e 1 chefe de gabinete ativos** por gabinete; assessor, auditor e
  secretário são ilimitados; vínculo inativo com o cargo não conta. Vale em
  todos os caminhos (Equipe, Administração do root, `gep:cargo-conceder`),
  porque todos gravam pelo `OfficePositionAssigner`, que confere com
  `lockForUpdate` sobre os membros do gabinete. Rede de segurança no banco:
  coluna gerada `gabinete_membros.cargo_lider_ativo` (só tem valor para
  vereador/chefe em vínculo ativo) com índice único por gabinete. Recusa com
  "Este gabinete já tem um vereador ativo (Nome). Remova ou altere o cargo
  dele antes." (idem chefe). Na Administração, o root pode marcar
  **Substituir**: retira o cargo do atual e atribui ao novo numa só operação,
  com um motivo e uma auditoria (`contexto.substituido_id`). Se o Hub reativar
  um vínculo cujo cargo de liderança já é de outra pessoa ativa, o vínculo
  volta **sem** esse cargo (log de aviso) — única exceção a "o sync nunca
  mexe no cargo".
- **Proteções da Equipe** (não valem para root nem para o comando de
  operador): ninguém atribui, altera ou remove o **próprio** cargo; só root
  atribui, altera ou remove o cargo de **vereador** (vereador e chefe
  gerenciam chefe, assessor, secretário e auditor; o seletor nem oferece
  vereador); e ninguém deixa o gabinete **sem nenhum** vereador/chefe ativo.
- **Quem atribui cargo**: root ou quem tem cargo de vereador/chefe de gabinete
  no gabinete, na Equipe (`PUT equipe/{usuario}/cargo`), com as proteções
  acima. Cada mudança grava
  `CARGO_ALTERADO` em `contexto_acesso_eventos` (`contexto`: `membro_id`,
  `de`, `para`). Pessoas e vínculos continuam só no Hub.

**Partida (primeiro cargo do gabinete) em tela, só root.** Em Administração →
Gabinetes, o botão "Equipe e cargos" (`/admin/gabinetes/{id}/cargos`,
`Admin\OfficePositionController`) lista os integrantes vinculados ao gabinete
(espelho do Hub, inclusive quem ainda não entrou: nome, e-mail, situação do
vínculo, cargo ou "Sem cargo") e deixa o root conceder, trocar ou retirar o
cargo de qualquer um, sem depender de já existir vereador/chefe no gabinete.
Regra em `OfficeAccess::canManagePositionsOnPlatform()` (só root); cargos
limitados a `OfficePosition::allowedFor()`; **motivo obrigatório**; grava
`CARGO_CONCEDIDO_POR_OPERADOR` com `usuario_id` = o root e `contexto` =
`membro_id`, `de`, `para`, `origem: tela_administracao`, `ator: root`,
`motivo`; idempotente. A lista de gabinetes marca com "Sem cargos" os que ainda
não têm nenhum integrante ativo com cargo. A tela não cria, edita nem remove
pessoas ou vínculos (isso é do Hub). A gravação é a mesma da Equipe e do
comando (`App\Services\Offices\OfficePositionAssigner`).

**Partida por comando (contingência).** Quem opera o servidor também concede
com `gep:cargo-conceder` (PLATFORM.md §3.1, padrão do
`gho:gestor-acesso-conceder`). Exige pessoa ativa com `hub_user_id`, gabinete
identificado pelo **id da unidade no Hub** (`gabinetes.hub_unidade_id`, o mesmo
sistema de identificadores da pessoa), vínculo ativo, cargo permitido ao tipo
do gabinete e `--motivo`; recusa root; é idempotente (cargo igual não grava
nada). Grava `CARGO_CONCEDIDO_POR_OPERADOR` em `contexto_acesso_eventos` com
`usuario_id` nulo e `contexto` = `membro_id`, `de`, `para`, `origem`,
`ator: sistema`, `motivo`. Aceita qualquer cargo permitido ao tipo, mas o uso esperado
é VEREADOR ou CHEFE_GABINETE; os demais são atribuídos depois pela Equipe.

```bash
php artisan gep:cargo-conceder 42 12 CHEFE_GABINETE --motivo="Partida do gabinete — chamado #123"
```

**Colunas inertes (limpeza pendente do schema).** `gabinete_membros.papel` e
`entidade_membros.papel` ficaram nullable e não são lidas (o sync do Hub grava
`null`; convites e a sincronização legada de contas criadas no GEP ainda
gravam valores, sem efeito); `entidade_convites.papel_*` idem; em
`users.role` só a marca `root` é lida. `App\Enums\AccessRole` e os valores
não-root de `UserRole` existem só para essas colunas.

**Passos restantes.** O Hub parar de enviar `papel` (claims, API de leitura e
webhook) — o GEP já ignora; remover as colunas inertes acima (trocar
`users.role` por um `is_root`); cargo de entidade para Câmara/Prefeitura;
poderes do secretário fora do módulo; cargos próprios para gabinete do
prefeito, secretaria e setor administrativo; GRI, GPC e demais produtos
adotarem o mesmo modelo (cargo/perfil local, Hub só vínculo).

## Até a integração

- Não criar telas ou regras novas de gestão de usuários no GEP.
- Nunca excluir contas fisicamente; desativar.
- Corrigir apenas defeitos que afetem o uso atual.
