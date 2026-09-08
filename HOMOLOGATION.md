# Homologação local do GOVNEX GAB

Este roteiro valida o MVP antes de uma futura publicação. Ele não executa deploy, não altera uma VPS e não usa credenciais de produção.

## Evidências automatizadas

- Visitantes são redirecionados ao login nas áreas protegidas.
- O administrador acessa o painel global e a gestão cadastral; só entra nos módulos operacionais depois de escolher explicitamente entidade e gabinete, com contexto isolado, entrada auditada e retorno visível à plataforma.
- Vereador, chefe de gabinete e assessor acessam dashboard, demandas, Kanban, cidadãos, categorias, bairros, agenda e configurações de conta.
- Vereador e chefe acessam equipe e relatórios; assessor recebe bloqueio do backend.
- Todos os perfis de gabinete são bloqueados na administração da plataforma.
- Cabeçalhos de segurança, isolamento entre gabinetes, IDOR, arquivos privados, filas, lembretes, exportações e consultas sem N+1 permanecem cobertos pela suíte.

Execute:

```bash
php artisan test --compact
vendor/bin/phpstan analyse --no-progress
npm run types:check
npm run build
```

## Roteiro visual para aceite

Use apenas o ambiente local com os usuários descritos no README.

### Administrador da plataforma

- Entrar e confirmar o dashboard global.
- Criar uma entidade e confirmar o redirecionamento para o cadastro do primeiro gabinete.
- Cadastrar o gabinete e seu responsável; confirmar que município, UF e fuso foram herdados da entidade.
- A partir de uma Câmara ou Prefeitura existente, usar “Novo gabinete” e confirmar que apenas tipos compatíveis são oferecidos.
- Suspender o gabinete e confirmar o bloqueio do usuário tenant.
- Reativar o gabinete e confirmar a recuperação do acesso.
- Criar dois gabinetes com combinações de módulos diferentes.
- Alterar a seleção completa, conferir as dependências e revisar o histórico imutável.
- Confirmar que Política desativada oculta a importação de dados do TSE.
- Com Política ativa, abrir o link oficial de um dataset, enviar o ZIP e acompanhar o processamento até a conclusão.
- Confirmar que o download automático aparece apenas como fallback, entra na fila `tse` e, diante de HTTP 403, orienta o administrador a usar o upload manual.

### Módulos por gabinete

- Manter um gabinete com todos os módulos ativos e confirmar o comportamento histórico integral.
- Manter outro somente com Relacionamento e confirmar sidebar e dashboard adaptados.
- Acessar diretamente as URLs de Demandas, Agenda, Eventos, Política, Relatórios e WhatsApp desativadas e confirmar HTTP 403.
- Reativar cada módulo e confirmar que os dados históricos voltam sem restauração manual.
- Confirmar que Demandas, Atendimentos, Agenda e Eventos exigem Relacionamento; Relatórios exige Demandas; WhatsApp exige Demandas, Agenda ou Política.
- Confirmar que campos de demanda em Agenda e Atendimentos são ocultados e rejeitados quando Demandas está desativado.
- Confirmar que callbacks WhatsApp continuam aceitos e idempotentes enquanto novos envios permanecem suprimidos.
- Confirmar que jobs pendentes de relatório, lembrete, TSE e WhatsApp não produzem efeito externo após a desativação.

### Vereador

- Conferir indicadores do dashboard e alternar o período.
- Criar cidadão e demanda; confirmar protocolo automático.
- Mover a demanda no Kanban, resolver, encerrar e reabrir.
- Cadastrar membro da equipe, categoria e bairro.
- Alterar configurações do gabinete.
- Solicitar exportações PDF e XLSX e processar a fila local.

### Chefe de gabinete

- Distribuir uma demanda para um assessor.
- Registrar encaminhamento, próxima ação e retorno recebido.
- Criar, reagendar e cancelar um compromisso.
- Gerenciar assessores e consultar relatórios.
- Confirmar que configurações exclusivas do vereador permanecem somente leitura.

### Assessor

- Criar cidadão e demanda.
- Atualizar demanda atribuída, adicionar atualização e anexo.
- Confirmar bloqueio à exclusão de demandas, gestão da equipe e relatórios.
- Verificar notificações e agenda.

### Responsividade e acessibilidade

- Repetir os fluxos essenciais em larguras de 375, 768 e 1440 pixels.
- Navegar login, menu, formulários, diálogos e Kanban somente com teclado.
- Conferir foco visível, nomes acessíveis, mensagens de erro e contraste nos temas claro e escuro.
- Instalar o PWA localmente, abrir a tela offline e confirmar que dados autenticados não aparecem no cache.

## Critérios de aprovação

- Nenhum erro 500, acesso cruzado entre gabinetes ou arquivo privado exposto.
- Ações negadas retornam 403 ou mensagem de validação, sem depender apenas da interface.
- Protocolo, histórico, notificações e auditoria são gerados uma única vez.
- Build, testes, análise estática e auditorias de dependências permanecem aprovados.
- O backfill deixa todos os gabinetes existentes com os oito módulos ativos antes de qualquer configuração posterior.
- Qualquer divergência deve ser registrada com perfil, rota, horário e passos de reprodução.
