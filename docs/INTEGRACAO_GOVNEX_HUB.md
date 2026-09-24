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
| ~~Administração → Gabinetes (responsável)~~ | — | Desligado em 24/09/2026: gabinete nasce no Hub, sem conta; o acesso vem dos vínculos. |
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
   `vinculo.alterado`, `vinculo.encerrado` e, desde 24/09/2026, os de estrutura
   `entidade.criada|alterada|removida` e `unidade.criada|alterada|removida`
   (ver "Estrutura definida pelo Hub" abaixo).

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

   ### Estrutura definida pelo Hub (24/09/2026)

   O Hub é a fonte da verdade do **nome e da situação** das entidades e
   unidades **já ligadas** (`entidades.hub_entidade_id`,
   `gabinetes.hub_unidade_id`). Renomear, suspender ou reativar no Hub chega ao
   GAB pelo webhook. Todo o resto — slug, tipo, cores, módulos, licença,
   timezone — continua do GAB.

   **Eventos.** `entidade.criada`, `entidade.alterada`, `entidade.removida`,
   `unidade.criada`, `unidade.alterada`, `unidade.removida`. Desde a fase 2
   (abaixo) o Hub os emite só para os sistemas notificáveis **habilitados na
   entidade** (para unidade, na entidade dela), e só quando muda campo
   relevante (`nome, slug, sigla, tipo, municipio, estado, status` na
   entidade; `nome, slug, tipo, ativa, unidade_pai_id` na unidade) ou a
   exclusão.

   ```json
   {
     "id": "<uuid>", "tipo": "entidade.alterada", "sistema": "GAB",
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

   **Como o GAB aplica** (`HubEstruturaSyncService`, chamado pelo
   `HubEventProcessor` antes da exigência de `pessoa`):

   | Situação | Resultado |
   |---|---|
   | `*.alterada` de item ligado | Aplica `nome` e situação: entidade `status = ativa` → `ATIVA`, qualquer outro → `SUSPENSA` (com `suspensa_em`); unidade `ativa` → gabinete `ativo`/`suspenso` (com `suspended_at`). Slug **nunca** é alterado. |
   | `*.removida` de item ligado | **Suspende**, nunca apaga (reversível; os dados operacionais ficam). |
   | Item não ligado | `Log::info` e **200** (`entidade_ignorada`/`unidade_ignorada`). Nunca 409/422. |
   | `*.criada` | Cria a entidade/o gabinete — ver "Estrutura criada no Hub nasce no GAB". Item já ligado cai na linha de `*.alterada`. |
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

   **Ordem de deploy: GAB antes do Hub.** O GAB antigo responde 422 a tipo
   desconhecido e o Hub trata 4xx como falha permanente: se o Hub subir antes,
   os eventos de estrutura ficam `Falhou`. Depois de publicar os dois, rode
   `hub:espelhar-estrutura --atualizar --dry-run` e, se coerente, sem
   `--dry-run`.

   **Fora de escopo da fase 1.** Conta (o GAB não a conhece), alteração de
   timezone e tipo depois de criado, e unidades aninhadas (o GAB é plano). A
   criação ficou para a fase 2, logo abaixo.

   ### Estrutura criada no Hub nasce no GAB (fase 2, 24/09/2026)

   O Hub é o **único ponto de criação** de entidade e gabinete. O que ele cria
   para o GAB nasce aqui sozinho, sem inventar dado: titular
   (`vereador_nome`), número eleitoral, cores e protocolo continuam sendo
   preenchidos no GAB depois (Configurações do gabinete / Administração →
   Gabinetes).

   **Habilitação por entidade (Hub).** Cada entidade declara no `/estrutura`
   do Hub os **sistemas habilitados** (tabela `entidade_sistema`; só
   administrador do Hub edita). Os avisos de estrutura da entidade e das
   unidades dela vão só para os habilitados. Habilitar o GAB numa entidade —
   inclusive ao criá-la — manda ao GAB, e só a ele, `entidade.criada` e
   `unidade.criada` de tudo o que já existe (pai antes do filho); desabilitar
   só para de avisar (nada é suspenso aqui). Com o GAB habilitado, a entidade
   precisa ter município e UF (próprios ou da organização). A migration do Hub
   habilita, para cada entidade, os sistemas em que ela já tem vínculo; o
   importador habilita o GAB no que veio do GAB — nenhum dos dois anuncia
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
   unidade `GABINETE` e origem no GAB.

   **Como o GAB cria** (`HubEstruturaSyncService::criarEntidade`/`criarUnidade`,
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
   vínculo se o Hub disser que o GAB não está habilitado, o tipo não tiver
   equivalente ou o Hub não responder — e aí vale a regra de antes: o login
   não desativa os ausentes.

   **Rede de segurança.** `php artisan hub:espelhar-estrutura --criar` cria o
   que falta (entidade com o GAB habilitado e tipo com equivalente, unidades
   raiz com tipo com equivalente); `--criar --dry-run` só lista o que
   nasceria e o motivo de cada item que não nasce.

   **Criação local bloqueada.** `admin.entities.create` e
   `admin.offices.create` redirecionam para Administração → Gabinetes com
   "A estrutura é criada no Govnex Hub"; `admin.entities.store` e
   `admin.offices.store` respondem 403. Os botões "Nova entidade"/"Novo
   gabinete" viraram atalhos para `{HUB_BASE_URL}/estrutura` (sem
   `HUB_BASE_URL`, só o aviso). A edição de gabinete na administração não cria
   nem altera mais a conta do responsável — ela é só exibida. Continuam
   editáveis no GAB os campos de domínio dele (titular, número eleitoral,
   contato, endereço, módulos, cores…).

   **Ordem de deploy: GAB antes do Hub.** O Hub só passa a emitir `*.criada`
   filtrado por habilitação depois do `migrate` (tabela `entidade_sistema`),
   que exige autorização. Depois de publicar os dois: habilitar o GAB numa
   entidade de teste no Hub, criar uma unidade e conferir o GAB; rodar
   `hub:espelhar-estrutura --criar --dry-run` para ver o que falta.
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
