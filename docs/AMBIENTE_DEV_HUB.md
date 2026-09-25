# Ambiente de desenvolvimento GAB + Hub (montagem do zero e estado do trabalho)

Guia para retomar o trabalho de integração com o Govnex Hub em outra máquina. O contrato técnico (rotas, eventos, papéis) fica em
[INTEGRACAO_GOVNEX_HUB.md](INTEGRACAO_GOVNEX_HUB.md); aqui estão a **ordem de montagem**, as **pegadinhas** e o **estado atual**.
Não há segredos neste arquivo: valores reais ficam nos `.env`, que não são versionados.

## O que NÃO vem pelo Git

- `.env` de cada projeto (segredos, portas, `HUB_*`).
- Chaves do Passport no Hub (`storage/oauth-private.key`, `oauth-public.key`, ignoradas): gerar de novo com `php artisan passport:keys`.
- Bancos de dev (MySQL local): tudo o que está abaixo é recriado pelos passos deste guia.
- Acesso ao GitHub: a `@govnex/ui` é um repositório privado (`git+https://github.com/juniorjmenezes/govnex-ui.git`); o `npm ci` precisa de credencial nessa máquina.
- Memória e planos do Claude Code (ficam na máquina em que a conversa aconteceu).

## Repositórios e portas de dev

| Produto | Repositório | Porta usada em dev |
|---|---|---|
| GAB | `juniorjmenezes/govnex-gab` | 8000 |
| GOVNEX API | `juniorjmenezes/govnex-api` | 8010 |
| Hub | `juniorjmenezes/govnex-hub` | 8020 |
| GRI | `juniorjmenezes/govnex-gri` | — |
| GPC | `juniorjmenezes/govnex-gpc` | — |
| `@govnex/ui` | `juniorjmenezes/govnex-ui` | — |

Requisitos: PHP 8.4 com a extensão **`sodium`** habilitada (`lcobucci/jwt`, dependência do Passport, exige), Composer, Node, MySQL/MariaDB.

## Montagem, na ordem

1. **Clonar e instalar** GAB e Hub: `composer install`, `npm ci`, copiar `.env.example` para `.env` e `php artisan key:generate`.
2. **Bancos**: criar `gabnex` (GAB) e `govnex_hub` (Hub). O Hub lê o banco do GAB pela conexão `gabnex` (`GABNEX_DB_*` no `.env` do Hub; só o `hub:importar-gab` usa).
3. **GAB**: `php artisan migrate:fresh --seed`. Usuários de demonstração, todos com senha `password` (só local): `admin@gabinetefacil.test` (root),
   `vereador@`, `chefe@` (administrador) e `assessor@` (operador), todos `@gabinetefacil.test`. Só o root entra por senha; os demais entram por SSO.
4. **Hub**: `php artisan migrate --seed` e `php artisan passport:keys`. No `.env` do Hub, `APP_URL` **precisa ser a URL real** (ex.: `http://127.0.0.1:8020`),
   senão o discovery OIDC anuncia endpoints errados.
5. **Importar a estrutura e as pessoas do GAB para o Hub**: `php artisan hub:importar-gab --simular` e depois sem `--simular`.
6. **Primeiro administrador do Hub**: `php artisan hub:conceder-administrador <email>` (sem isso ninguém abre o painel — o Hub exige papel no sistema HUB).
   Defina uma senha de dev para essa pessoa (ex.: `php artisan tinker` e `User::where('email', ...)->update(['password' => Hash::make('...')])`).
7. **Servidores**: `php artisan serve --port=8020` + `npm run dev` no Hub; `php artisan serve --port=8000` no GAB. **Deixe `php artisan queue:work` rodando no Hub**:
   os webhooks só saem com um worker (a fila é `database`).
8. **Credenciais de integração do GAB**, na tela do Hub `Sistemas › GAB › Credenciais` (pede confirmação de senha):
   - registrar o cliente OIDC com a URI de retorno `http://127.0.0.1:8000/auth/hub/callback` (o segredo aparece **uma vez**);
   - gerar o segredo de API (vira `HUB_API_SECRET`);
   - `webhook_url` = `http://127.0.0.1:8000/api/integrations/hub/webhook` (**com `/api`** — sem ele o GAB responde 404);
   - o card "Resumo para o .env" monta o bloco pronto.
9. **`.env` do GAB**: `HUB_BASE_URL` (URL do Hub), `HUB_SISTEMA_CODIGO=GAB`, `HUB_OIDC_CLIENT_ID`, `HUB_OIDC_CLIENT_SECRET`, `HUB_OIDC_REDIRECT`, `HUB_API_SECRET`. Depois `php artisan cache:clear` (o GAB guarda o discovery em cache por 1 hora).
10. **Ligar a estrutura**: no GAB, `php artisan hub:espelhar-estrutura --dry-run` e depois sem `--dry-run` (preenche `hub_entidade_id`/`hub_unidade_id` por slug).
11. **Testar**: em `http://127.0.0.1:8000` clicar em "Entrar com Govnex Hub" com uma pessoa do Hub que tenha vínculo com o GAB (o importador cria as do seed; defina uma senha para ela no Hub).

## Pegadinhas já descobertas

- Endereço do webhook tem `/api` (`routes/api.php` fica sob esse prefixo).
- Sem `queue:work` no Hub, nenhum webhook é entregue; `hub:reprocessar-webhooks-pendentes` (agendado a cada 5 min) só reenfileira, não entrega.
- `APP_URL` do Hub sem porta faz o SSO redirecionar para `http://localhost/...`.
- O login do Hub é uma visita do Inertia; o destino OIDC é tratado por `RespostaDeLogin` (navegação completa). Não trocar por redirect simples.
- O GAB responde 422 a tipos de evento desconhecidos: **em produção, publicar o GAB antes do Hub**.
- Vínculo do Hub para entidade/unidade ainda não espelhada no GAB não desativa acessos existentes (só é ignorado com aviso no log); rodar o espelhamento resolve.
- Nome e situação de entidades/gabinetes **ligados ao Hub** não são editáveis no GAB (vêm do Hub).
- Um `UPDATE` direto no banco não gera evento; alterar por model/tela.
- Pessoas do Hub sem papel no sistema HUB entram pelo SSO nos outros produtos, mas o painel do Hub responde 403 para elas.

## Estado do trabalho (24/09/2026)

**Feito e enviado ao GitHub**
- Hub como provedor OIDC (Passport), 2FA/passkeys (Fortify), webhook assinado com fila de reprocessamento, API `/api/v1` protegida.
- GAB piloto: SSO, provisionamento de pessoa e vínculos (login + webhook), papéis `root/administrador/operador/auditor`, auditor somente leitura (servidor e interface).
- Hub: gestão de pessoas e vínculos com autorização por papel no sistema HUB, cadastro público desligado, página de credenciais.
- Estrutura: nome e situação de entidades/unidades ligadas propagam do Hub ao GAB.
- GRI: modelo multiempresa (`organization_members`, papel de acesso separado de função de negócio), `hub_entidade_id`, SSO e webhook implementados (24/09/2026) — ver "Decisões pendentes" abaixo. GPC: modelo multiempresa (`dea085f`), SSO e webhook implementados e validados em 25/09/2026 (ver item 4 abaixo).

**Estrutura criada no Hub nascendo no GAB — validada ponta a ponta em 25/09/2026**
- Criação de estrutura no Hub nascendo no GAB (Hub: `entidade_sistema`, tipo "Gabinete independente", payload ampliado; GAB: `HubEstruturaSyncService`, `HubTipoMapper`, `EstruturaProvisioningService`,
  criação local bloqueada, `hub:espelhar-estrutura --criar`). As suítes passam (GAB 593, Hub 174), mas:
  1. a migration `2026_09_24_120000_create_entidade_sistema_table` **não foi aplicada** em nenhum banco de dev (rodar `php artisan migrate` no Hub);
  2. `hub:reclassificar-gabinetes-independentes --dry-run` (Hub) precisa ser revisado antes de aplicar;
  3. falta o teste manual: criar entidade no `/estrutura` do Hub com o GAB habilitado, criar unidade, `queue:work`, conferir no GAB e entrar por SSO.
  O contrato dessa fase está em `INTEGRACAO_GOVNEX_HUB.md` (GAB) e no `AGENTS.md`/`docs/AUTORIZACAO.md` (Hub); o trabalho foi interrompido durante a edição
  dessas docs, então vale uma leitura rápida para confirmar que estão completas.

**Pendente, na ordem sugerida** (atualizado em 25/09/2026: os itens 1 a 4 estão validados)
1. ~~Validar a criação de estrutura~~ **Validado (25/09/2026)**: migration `entidade_sistema` aplicada no Hub; `hub:reclassificar-gabinetes-independentes` aplicado (entidades 12 e 13); criar entidade no `/estrutura` com o GAB habilitado gerou entidade no GAB (tipo, município, licença, 9 módulos); criar unidade gerou gabinete `GABINETE_VEREADOR` sem usuário; entidade sem o GAB habilitado não gerou nada; vínculo concedido no Hub apareceu no gabinete novo; criação local (`admin/gabinetes/novo`, `admin/entidades/nova`) bloqueada. Observação: `UnidadeController::store` do Hub dá 500 se `unidade_pai_id` não vier no corpo (a tela sempre envia).
2. ~~**Corte da gestão de contas no GAB**~~ Concluído em 24/09/2026 do lado do código: Equipe (`TeamController`, `UserPolicy`) e convite de entidade (`EntidadeInvitationController::store`) ficaram somente leitura, com aviso "Gerenciado no Govnex Hub — altere lá" (`HubManagedHint`). Convites pendentes anteriores ao corte ainda podem ser aceitos. Usuários root continuam locais (decisão #6), fora de escopo. O login local do Fortify já recusava não-root desde a fase 2. Falta, em produção: decisão sobre remover senha/2FA/passkeys locais dos não-root (passo 5 do plano do `INTEGRACAO_GOVNEX_HUB.md`) — isso é limpeza, não bloqueia nada. **Validado via HTTP (25/09/2026)**: com sessão SSO de administrador, `POST equipe` e `POST convites` devolvem 403 "Gerenciado no Govnex Hub — altere lá" e nenhum usuário é criado (a aparência das telas não foi conferida no navegador).
3. **GRI**: consumidor do Hub implementado e enviado (`e07403b`, 64 testes) e **handshake real validado em 25/09/2026**: Sistema GRI registrado no Hub (cliente OIDC + segredo de API + webhook `http://127.0.0.1:8040/api/integrations/hub/webhook`), `.env` do GRI preenchido (não versionado), organização ligada por `hub_entidade_id`, login SSO criou a conta com `access_role=administrador`, webhook mudou para auditor e o encerramento desativou o vínculo. O banco de dev do GRI precisou de `migrate:fresh --seed` (colunas do Hub estão na migration original) e `composer install` (Socialite). **Atenção em outra máquina**: existe uma versão descartada do modelo de vínculo (branch local `backup/reimplementacao-vinculo-descartada`, não usar); a base real é `a6a5f3c` + `e07403b`. **Sempre `git fetch`/`git log origin/main` antes de reimplementar algo que este doc descreve como pendente.** As decisões de modelagem em `govnex-gri/docs/INTEGRACAO_GOVNEX_HUB.md` ("Decisões pendentes") continuam precisando de revisão humana.
4. **GPC**: consumidor do Hub implementado (70 testes) e **handshake real validado em 25/09/2026**, no mesmo roteiro do GRI: Sistema GPC semeado no Hub (`SistemaSeeder`, id 5), cliente OIDC + segredo de API + webhook `http://127.0.0.1:8050/api/integrations/hub/webhook`, `.env` do GPC preenchido (não versionado), tenant ligado por `hub_entidade_id`; login SSO criou a conta com `access_role=administrador`, o webhook mudou para auditor (leitura 200, escrita 403 na API) e o encerramento desativou o vínculo. O banco de dev do GPC precisou de `migrate:fresh --seed` (colunas do Hub estão nas migrations originais) e `composer install` (Socialite). Decisões fora do molde do GRI (middleware `EnsureTenantAccess` em `/api/*`, auditor imposto por método HTTP, função local desconhecida vale auditor, botões de escrita ainda visíveis ao auditor) estão no `docs/INTEGRACAO_GOVNEX_HUB.md` do próprio GPC. Mapeamento de papéis: `administrator`→administrador, `analyst`→operador, revisores só leitura→auditor.
5. **Decisões de GRI/GPC fechadas em 25/09/2026** (registradas em cada `docs/INTEGRACAO_GOVNEX_HUB.md`): entidade suspensa no Hub bloqueia o acesso (validado por webhook nos dois: 403 ao suspender, 200 ao reativar); função local desconhecida vale auditor; login local só para contas `users.is_root` quando o Hub está configurado (sem as variáveis `HUB_*` continua aberto); desligamento derruba a sessão (GAB `EnsureUserIsActive`, GRI `EnsureOrganizationAccess`, GPC `EnsureTenantAccess`/`/auth/me`); `hub:espelhar-estrutura` (`--dry-run/--atualizar/--criar`) em GRI e GPC casa por nome normalizado só as entidades com `habilitado=true` para o produto; botões de escrita escondidos para o auditor no GRI e no GPC (`useCanWrite`). **Os bancos de dev do GRI e do GPC precisam de `migrate:fresh --seed` por causa de `users.is_root`**; depois, no Hub, habilitar o produto na entidade (campo "Sistemas habilitados") e rodar `hub:espelhar-estrutura` no produto. Contas root de demonstração: GRI `admin@govnex.local`, GPC `demo@govnex.com.br`.
6. Fora de escopo por ora: GTR; edição de tipo/município da entidade depois de criada; unidades aninhadas; delegação de administração do Hub por entidade.

Preferências do dono do projeto que valem para qualquer continuação: telas com muita informação viram **páginas**, não dialogs; dados de trabalho em **tabelas**; ícones só via `components/icons`; nunca commitar `.env`, chaves ou dados pessoais.
