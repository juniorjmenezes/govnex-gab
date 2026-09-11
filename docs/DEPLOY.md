# Deploy do GOVNEX GAB

Este é o runbook canônico de publicação. Deploy, migrations externas e alterações de infraestrutura exigem solicitação explícita.

## Destino confirmado

> **Nota sobre o rename:** os paths, scripts e nomes abaixo (`gabnex` em `/srv/f3/apps/gabnex`, nos scripts `*-gabnex-*.sh`, no projeto Compose `f3-gabnex`, nos timers systemd `@gabnex.*` e no domínio) refletem a infraestrutura real hoje, versionada no repositório separado `f3-infra`, ao qual este agente não tem acesso. Eles **não foram renomeados** — fazer isso exigiria uma mudança coordenada nesse outro repositório e no DNS antes que a documentação aqui deixasse de mentir sobre o que existe de fato no servidor. Só o nome do produto no texto e os comandos artisan (que já mudaram no código desta sessão) foram atualizados.

O GOVNEX GAB está publicado na `f3-vps` e usa a infraestrutura versionada em `C:\xampp82\htdocs\f3-infra`:

- acesso administrativo: `ssh f3-vps`;
- domínio: `https://gabnex.f3sistemas.app.br`;
- saúde: `https://gabnex.f3sistemas.app.br/up`;
- plataforma no servidor: `/opt/f3/platform`;
- aplicação: `/srv/f3/apps/gabnex`;
- segredos: `/etc/f3/secrets/gabnex`;
- backups: `/var/backups/f3/apps/gabnex` e repositório Restic próprio;
- Compose: `/opt/f3/platform/apps/gabnex/compose.yml`;
- projeto Compose: `f3-gabnex`.

Os serviços são `db`, `web`, `worker-default`, `worker-tse`, `worker-whatsapp` e `scheduler`. Somente `web` participa da rede externa `proxy`; nenhum serviço publica porta no host. Não altere a `minha-vps` para publicar ou recuperar o GOVNEX GAB.

## 1. Parâmetros obrigatórios

Registre no plano da publicação, sem gravar segredos neste arquivo:

- commit ou tag imutável a publicar;
- domínio público aprovado;
- nome do projeto Compose e serviços afetados;
- URL pública e endpoint de saúde;
- serviços web, worker, scheduler, banco e proxy envolvidos;
- diretório de backups e prazo de retenção;
- migrations previstas e estratégia de rollback;
- responsável pela autorização e pela validação funcional.

Não reutilize credenciais, certificados, banco ou volumes de outra aplicação por conveniência.

## 2. Pré-validação local

Trabalhe a partir de uma revisão commitada e confira o worktree:

```bash
git status --short --branch
git diff --check
git rev-parse HEAD
composer validate --no-check-publish --no-interaction
composer ci:check
npm ci
npm run build
```

Além disso:

- revise o diff e os arquivos novos;
- procure credenciais e dados pessoais;
- confirme PHP 8.4.1 ou superior e compatibilidade do lockfile;
- valide exportações PDF/XLSX quando alteradas;
- não execute APIs externas, testes pagos ou migrations sem autorização específica.

### Gate da modularização por gabinete

Quando a release incluir `2026_08_10_000001_create_gabinete_module_tables.php`:

1. faça dump completo do banco e valide sua leitura;
2. execute a migration primeiro em clone descartável do banco de produção;
3. confirme que cada gabinete existente recebeu os oito módulos ativos;
4. confirme que `gabinete_modulo_eventos` não contém segredos ou dados pessoais;
5. mantenha os módulos ativos durante o smoke test inicial;
6. teste uma desativação somente em gabinete controlado, após o aceite do comportamento integral.

A migration preserva o comportamento anterior por backfill. Não desative módulos em massa durante a mesma janela de publicação. O catálogo e os critérios de aceite estão em [`govnexgabmodulos.md`](../govnexgabmodulos.md).

### Gate da primeira publicação (fundação multientidade)

A aplicação ainda não foi publicada em produção. A hierarquia `entidade → gabinete → equipe` já nasce embutida no histórico de migrations (não existe backfill de dados legados a validar, porque não existe base de produção anterior a migrar). Ainda assim, antes da primeira publicação:

1. use um clone descartável do MariaDB 11.4 e rode `migrate:fresh --seed` do zero;
2. confirme que as 27 migrations aplicam sem erro e que `entidades`, `gabinete_membros`, `entidade_licencas` e `entidade_modulos` ficam consistentes com o seeder;
3. confirme planos/licenças (`LEGADO_COMPLETO`) e todos os módulos institucionais disponíveis para as entidades semeadas;
4. homologue gabinete independente, Câmara e Prefeitura antes de preparar o pacote final.

Na primeira publicação, não crie comunicados, solicitações, transferências ou conexões WhatsApp reais durante o smoke test. As URLs legadas devem apenas redirecionar para o contexto canônico e registrar diagnóstico sanitizado. O contrato está em [`govnexgabentidades.md`](../govnexgabentidades.md).

## 3. Release seletiva

Monte a release a partir dos arquivos rastreados do commit aprovado. Nunca inclua:

- `.env`, credenciais, chaves privadas ou tokens;
- `vendor`, `node_modules`, caches, logs e temporários;
- uploads, anexos e arquivos privados de usuários;
- banco local, volumes Docker ou dados demonstrativos.

O `compose.yaml` da raiz é exclusivo do Docker Desktop/WSL. A composição de produção é mantida no `f3-infra`; não envie a composição local à VPS.

Para rotinas remotas com várias etapas, crie um script local, valide sua sintaxe, transfira-o e execute-o no servidor. Evite comandos extensos com PowerShell, SSH, Bash e SQL aninhados na mesma string.

## 4. Backup antes da mudança

Antes de substituir arquivos ou executar migrations:

1. crie um diretório de backup com timestamp e permissão restrita;
2. preserve ambiente, Compose, configuração do proxy e manifesto da revisão ativa sem imprimir segredos;
3. faça dump consistente do MariaDB quando houver risco para estrutura ou dados;
4. preserve o armazenamento privado e os volumes persistentes afetados;
5. registre IDs ou digests das imagens e mantenha uma imagem de rollback;
6. gere checksums e valide a leitura do dump e dos arquivos;
7. registre o estado dos serviços, filas e jobs em processamento.

Backup no mesmo servidor não protege contra perda total da VPS. A estratégia definitiva deverá incluir uma cópia externa e teste periódico de restauração.

## 5. Aplicação da release

Gere a release somente a partir do commit aprovado e envie-a para uma nova pasta em `/srv/f3/apps/gabnex/releases`. Depois execute, a partir de `/opt/f3/platform`:

```bash
scripts/activate-gabnex-release.sh ARQUIVO_RELEASE COMMIT SHA256
scripts/write-app-compose-env.sh gabnex
scripts/build-gabnex-image.sh
scripts/reconcile-gabnex.sh
scripts/validate-gabnex.sh
```

O build multi-stage instala dependências sem desenvolvimento, compila os assets e serve somente `public/`. A imagem deve usar uma tag imutável vinculada ao commit e ao hash da release.

Migrations devem ocorrer apenas depois do backup, com revisão de FKs, índices, impacto e reversibilidade. Para a primeira base demonstrativa vazia:

```bash
scripts/initialize-gabnex.sh
```

Esse comando sobe o banco, executa `migrate --force` e chama `govnexgab:bootstrap-demo` com confirmação explícita. O bootstrap recusa banco já preenchido e grava credenciais uma única vez em `/run/gabnex-bootstrap/credentials.txt`, com modo `600`. Guarde-as fora do servidor, ative o TOTP do administrador e remova o arquivo. Nunca use `migrate:fresh` ou o bootstrap em uma base operacional.

Para configurar SMTP sem exibir a senha:

```bash
scripts/configure-gabnex-smtp.sh
scripts/test-gabnex-smtp.sh fabriciovlw1@gmail.com
```

O ambiente atual usa `smtpi.kinghost.net:587` com STARTTLS e uma conta exclusiva. A senha permanece apenas em `/etc/f3/secrets/gabnex`.

Não reinicie banco, Traefik ou aplicações vizinhas sem necessidade. Rotas do Traefik devem usar labels, nome de serviço e rede declarada, nunca IP efêmero de container.

## 6. Processos contínuos

A produção supervisiona os seis serviços Compose. `worker-default` processa `default`, `worker-tse` processa as sincronizações do TSE pela GOVNEX API e o PollingData na fila `tse`, `worker-whatsapp` processa somente `whatsapp` e `scheduler` executa `schedule:work`. Não combine esse scheduler com cron HTTP ou outro `schedule:run`.

Os dados do TSE vêm da GOVNEX API, sem agendamento automático. Depois da implantação, dispare uma sincronização controlada em Sincronização política e confira o worker:

```bash
scripts/test-gabnex-tse.sh
scripts/validate-gabnex.sh
```

### Preparação do WhatsApp

O handoff do cliente técnico `GABINETE` é executado no gateway e produz um
arquivo temporário `600`. Importe-o somente pelo script versionado do
`f3-infra`:

```bash
/opt/f3/platform/scripts/configure-gabnex-whatsapp.sh /CAMINHO/gabnex.env
/opt/f3/platform/scripts/configure-gabnex-whatsapp-account.sh ACCOUNT_ID_0874
/opt/f3/platform/scripts/reconcile-gabnex.sh
```

O importador mantém `WHATSAPP_REAL_ENABLED=false`. Depois das migrations,
execute no container web:

```bash
php artisan govnexgab:whatsapp-audit
```

Cadastre os templates, sincronize sua aprovação e selecione exatamente um
integrante da equipe e um cidadão consentidos antes de usar `--strict`. Ativar
envio real, submeter templates ou chamar a Meta exige autorização separada.
Consulte [`govnexgabwhatsapp.md`](../govnexgabwhatsapp.md) para o contrato completo.

Para mudança de WABA, use primeiro a simulação do comando abaixo. A execução
confirmada recria somente as oito finalidades operacionais na conta configurada
e não inclui pesquisa eleitoral:

```bash
php artisan govnexgab:whatsapp-recreate-templates
php artisan govnexgab:whatsapp-recreate-templates --confirm --submit \
  --admin-email=fabriciovlw1@gmail.com
```

Sincronize depois da resposta da Meta. Nenhum template é ativado
automaticamente e `WHATSAPP_REAL_ENABLED` permanece `false`.

O WhatsApp real nasce desligado e só pode ser habilitado depois da auditoria
estrita, da aprovação dos templates e de um ensaio controlado. Não apresente
uma notificação simulada como envio real.

## 7. Validação pós-deploy

Confirme, no mínimo:

- `GET /up` retorna HTTP 200 por HTTPS;
- login administrativo e login de um gabinete funcionam;
- cookies e cabeçalhos defensivos permanecem ativos;
- assets são gerados com HTTPS atrás do proxy;
- os quatro perfis respeitam suas permissões;
- não há acesso cruzado entre gabinetes ou exposição de arquivo privado;
- PDF e XLSX, incluindo a logo, são gerados;
- worker processa um job controlado e scheduler executa uma tarefa prevista;
- `worker-whatsapp` consome somente sua fila e o comando de prontidão não
  encontra contatos ou finalidades habilitados indevidamente;
- logs não contêm segredos e `failed_jobs` não cresce inesperadamente;
- serviços estão saudáveis, sem reinícios contínuos.

Também valide e habilite a proteção operacional:

```bash
systemctl enable --now f3-app-backup@gabnex.timer
systemctl enable --now f3-app-restore-test@gabnex.timer
systemctl start f3-app-backup@gabnex.service
systemctl start f3-app-restore-test@gabnex.service
```

O Uptime Kuma monitora `/up`. Os backups atuais são locais e não protegem contra perda total da VPS.

Registre commit, horários, arquivos, migrations, imagens, checksums e resultado dos testes em um manifesto da publicação.

## 8. Rollback

Em falha:

1. retire temporariamente o router ou pare somente o projeto `f3-gabnex`;
2. preserve evidências e o estado atual antes de restaurar;
3. restaure a release e a imagem anteriores no arquivo Compose de ambiente;
4. execute `scripts/reconcile-gabnex.sh` e reinicie os processos compatíveis;
5. repita healthcheck, login e smoke tests.

Se a regressão estiver limitada ao WhatsApp, defina
`WHATSAPP_REAL_ENABLED=false`, coloque os gabinetes em `OFF` e pare somente
`worker-whatsapp`. Preserve outbox e callbacks para reconciliação; nunca faça
retry em massa de resultados ambíguos.

Se a regressão estiver limitada à modularização, restaure a release anterior e mantenha `gabinete_modulos` e `gabinete_modulo_eventos` no banco. A release anterior não consulta essas tabelas. Não apague eventos nem dados de negócio durante a estabilização.

Restaure o banco somente se a mudança tiver alterado estrutura ou dados de forma incompatível. Como isso pode apagar operações posteriores ao backup, a restauração exige confirmação explícita e uma última cópia do estado atual. Prefira migration corretiva progressiva quando for segura.

Não remova backups nem artefatos de rollback durante o período de estabilização.
