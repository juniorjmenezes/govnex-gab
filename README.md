# GOVNEX GAB

Aplicação SaaS para organizar demandas recebidas por gabinetes parlamentares. O MVP substitui controles em WhatsApp, cadernos e planilhas por uma base isolada, auditável e responsiva.

## Estado da implementação

- Etapa 1 — fundação Laravel, React, Inertia, MariaDB, autenticação e preset: concluída.
- Etapa 2 — design system, shell responsivo e estados reutilizáveis: concluída.
- Etapa 3 — multiempresa, perfis, isolamento e permissões: concluída.
- Etapa 4 — cidadãos, categorias, bairros, equipe e configurações: concluída.
- Etapa 5 — demandas, protocolo, filtros, responsáveis, prazos e transições: concluída.
- Etapa 6 — histórico expandido, observações internas, anexos privados e auditoria detalhada: concluída.
- Etapa 7 — Kanban responsivo, drag-and-drop acessível, atualização otimista e rollback: concluída.
- Etapa 8 — Encaminhamentos, prazos de resposta, documentos relacionados e auditoria: concluída.
- Etapa 9 — dashboard gerencial, indicadores, gráficos, prazos e atividade da equipe: concluída.
- Etapa 10 — relatórios gerenciais, filtros e exportações privadas em PDF/XLSX por fila: concluída.
- Etapa 11 — notificações internas, agenda, lembretes em fila, WhatsApp simulado e PWA: concluída.
- Etapa 12 — administração da plataforma, gabinetes, situação da conta e métricas globais: concluída.
- Etapa 13 — segurança, acessibilidade, desempenho, preparação de produção e auditoria final: concluída.

## Tecnologias

- PHP 8.4.1+ e Laravel 13
- MariaDB 12 / MySQL compatível
- Inertia.js 3, React 19 e TypeScript estrito
- Vite 8, Tailwind CSS 4 e Inter Tight Variable / Fira Code Variable
- shadcn/ui com preset oficial `b7D49K45A` (`radix-sera`, base `stone`)
- React Hook Form, Zod, Recharts e date-fns
- DomPDF e OpenSpout para exportações PDF e XLSX
- Pest, Laravel Pint e Larastan/PHPStan

## Instalação local

1. Use PHP 8.4.1 ou superior e execute `composer install`.
2. Execute `npm install`.
3. Copie `.env.example` para `.env` e execute `php artisan key:generate`.
4. Configure o MariaDB no `.env`.
5. Execute `php artisan migrate --seed`.
6. Execute `php artisan storage:link` somente para logos públicas; anexos usam storage privado.
7. Inicie com `composer run dev`.

Esse comando mantém o servidor Laravel, o Vite e o worker da fila de banco ativos. A sincronização política pode então ser iniciada e acompanhada integralmente pelo painel administrativo, sem comandos adicionais.

Nunca versione senhas, tokens ou credenciais reais.

## Credenciais de desenvolvimento

Disponíveis apenas em `local` e `testing`, todas com senha `password`:

- `admin@gabinetefacil.test`
- `vereador@gabinetefacil.test`
- `chefe@gabinetefacil.test`
- `assessor@gabinetefacil.test`

## Multientidade e segurança

O tenant raiz é a entidade e cada registro operacional continua pertencendo a um gabinete técnico identificado por `gabinete_id`. A plataforma atende gabinete independente, Câmara Municipal e Prefeitura, mantendo a hierarquia `entidade → gabinete → equipe`.

O isolamento combina `EntidadeContext`, `GabineteContext`, vínculos contextuais, global scopes, route model binding, middleware de conta ativa, Form Requests e Policies. URLs canônicas carregam entidade e gabinete explicitamente; rotas legadas apenas redirecionam de forma auditada. Gestores institucionais não recebem acesso automático aos dados privados dos gabinetes.

Entidades podem configurar identidade, referências territoriais, licenças e cotas. Consulte [`govnexgabentidades.md`](govnexgabentidades.md) para o contrato funcional e [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) para os limites técnicos.

## Módulos por gabinete

O administrador da plataforma pode habilitar, por gabinete, Relacionamento, Demandas, Atendimentos, Agenda, Eventos, Política, Relatórios e WhatsApp. O núcleo de autenticação, perfil, equipe, configurações e notificações internas permanece sempre ativo.

Os dados oficiais do TSE — base de municípios TSE/IBGE, perfil do eleitorado, candidaturas, comparecimento, votação nominal, locais de votação, votação por seção e registro de pesquisas — vêm todos da [GOVNEX API](https://github.com/juniorjmenezes/govnex-api), aplicação companheira que centraliza datasets públicos. Não há upload de arquivo nem download direto do TSE. Em `/admin/sincronizacao-politica`, um único card lista os oito datasets na ordem em que dependem uns dos outros: escolha a eleição e sincronize cada linha. A base de municípios não tem ano, e os datasets que não se aplicam ao tipo da eleição escolhida (votação nominal, locais e seções só em eleição municipal; registro de pesquisas só em eleição geral) ficam indisponíveis. A sincronização roda na fila `tse`; `GOVNEX_API_URL` e `GOVNEX_API_KEY` configuram o acesso (sem a key, ainda funciona, com limite de requisições mais baixo), `TSE_TIMEOUT` limita cada requisição e `TSE_WORKER_MEMORY_LIMIT` a memória do worker. A mesma tela concentra a sincronização das pesquisas do PollingData, disparada por gabinete com o módulo Política ativo.

Eleitorado e votação por seção são publicados um dataset por UF, então a cobertura cresce aos poucos conforme mais estados chegam à GOVNEX API. A plausibilidade de cada UF do eleitorado é validada contra a base local de municípios (`MunicipioEleitoral`) já importada, e uma linha cuja UF não bate com o dataset derruba a sincronização. A votação por seção só grava os votos dos titulares dos gabinetes; um gabinete cadastrado depois recebe a sua assim que o titular é resolvido. A votação nominal é gravada um estado por vez e exige o dataset na ordem original do CSV do TSE, agrupado por UF.

O GAB encontra cada dataset pelo **nome com que ele foi cadastrado na GOVNEX API**, não por busca aproximada: o slug é o nome do arquivo oficial do TSE em kebab-case, com o recorte no fim — `{nome-oficial}`, `{nome-oficial}-{ano}` ou `{nome-oficial}-{ano}-{uf}`. Quem cadastra o dataset do outro lado deriva o slug do próprio ZIP que baixou, sem tabela de tradução. A fonte esperada é `tse`; as demais fontes do catálogo são varridas depois, como rede de segurança. Só entra dataset com o último import concluído. `App\Services\Politics\Tse\GovnexApiDatasetCatalog` é a lista canônica:

| Dataset | Slug | Exemplo |
| --- | --- | --- |
| Município TSE/IBGE | `municipio-tse-ibge` | `municipio-tse-ibge` |
| Perfil do eleitorado | `perfil-eleitorado-{ano}-{uf}` | `perfil-eleitorado-2026-ce` |
| Candidaturas | `consulta-cand-{ano}` | `consulta-cand-2024` |
| Comparecimento | `detalhe-votacao-munzona-{ano}` | `detalhe-votacao-munzona-2024` |
| Votação nominal | `votacao-candidato-munzona-{ano}` | `votacao-candidato-munzona-2024` |
| Locais de votação | `eleitorado-local-votacao-{ano}` | `eleitorado-local-votacao-2024` |
| Votação por seção | `votacao-secao-{ano}-{uf}` | `votacao-secao-2024-sp` |
| Registro de pesquisas | `pesquisa-eleitoral-{ano}` | `pesquisa-eleitoral-2024` |

Nos datasets de votação por seção, declare `SQ_CANDIDATO` como campo filtrável na GOVNEX API. Com isso o GAB pede só as linhas do titular de cada gabinete — uma consulta por gabinete, algumas dezenas de linhas — em vez de ler o estado inteiro, que passa de 1,5 milhão de linhas. Sem a declaração a importação continua funcionando, só que lendo tudo. A GOVNEX API cria sozinha o índice de cada campo filtrável.

Em desenvolvimento, sirva a GOVNEX API por um servidor web de verdade, não pelo `php artisan serve`: ele entrega respostas grandes cortadas no Windows (medido: 11 de 16 requisições de ~700KB ficaram ~19s penduradas e vieram incompletas), o que derruba a leitura de qualquer dataset grande. Servida pelo Apache local, a mesma requisição responde em ~0,6s, 16 de 16 completas. `GOVNEX_API_URL` aponta para esse endereço.

Módulos desativados somem da navegação, retornam HTTP 403 em acesso direto e impedem novos jobs ou efeitos externos. Os dados existentes não são apagados e voltam a ficar disponíveis após reativação. Dependências são validadas em bloco, sem ativação silenciosa. Consulte [`govnexgabmodulos.md`](govnexgabmodulos.md) para o catálogo e [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) para os limites técnicos.

## Demandas e protocolo

A demanda registra cidadão, título, descrição, categoria, bairro, localização, prioridade, origem, responsável, abertura, prazo e conclusão.

O protocolo é criado exclusivamente no backend no formato `{ANO}-{SEQUENCIAL}`, por gabinete e ano. A tabela `demanda_protocol_sequences` usa chave composta e bloqueio transacional para impedir repetição em requisições concorrentes. Também há restrição única em `gabinete_id + protocolo`.

Status: Nova, Em andamento, Aguardando, Resolvida e Encerrada — status representa só "onde a demanda está"; o que aconteceu fica na timeline e o que precisa acontecer fica na próxima ação. As transições diretas são permissivas e validadas no backend; sair de Resolvida ou Encerrada é sempre uma reabertura explícita. Resolver preenche `concluida_em` e aceita um resultado opcional (Atendida, Parcialmente atendida, Não atendida, Orientação prestada, Encaminhada definitivamente, Duplicada, Outra); encerrar preenche `encerrada_em` separadamente; reabrir limpa as duas datas e o resultado, sempre preservando o histórico.

A listagem tem paginação, busca, ordenação e filtros processados no Laravel. Os filtros permanecem na URL e incluem status, prioridade, categoria, bairro, cidadão, responsável, origem, datas, atraso, conclusão e demandas sem responsável.

## Dashboard gerencial

A rota `/dashboard` apresenta um retrato operacional do gabinete: demandas abertas e por etapa, atrasadas, próximas do prazo, resolvidas no mês, não resolvidas, cidadãos cadastrados e tempo médio de resolução. O filtro de 30, 90, 180 ou 365 dias controla as análises históricas e listas recentes, enquanto os totais operacionais preservam o retrato atual.

Os gráficos mostram evolução mensal, status, categoria, bairro, responsável e origem. As consultas são agregadas no MariaDB, limitam rankings e listas, carregam apenas relações necessárias e mantêm o isolamento por gabinete. A tela também reúne demandas recentes, atrasos, próximos prazos e a atividade mais recente da equipe.

## Kanban de demandas

A rota `/demandas/kanban` organiza as demandas nas cinco colunas de status, como visão alternativa à Caixa de entrada — não a tela principal. Os cards mostram protocolo, título, cidadão, bairro, categoria, prioridade, responsável, prazo, atraso e quantidade de anexos.

A movimentação usa dnd-kit e funciona com mouse, toque e teclado. O card é atualizado de forma otimista; se o backend rejeitar a permissão ou a transição, o quadro restaura o estado anterior e apresenta o erro. As mesmas regras de domínio, Policies, isolamento por gabinete e auditoria usadas na tela de detalhes protegem o Kanban.

Busca, prioridade e responsável são filtrados no servidor. Cada coluna carrega no máximo 60 registros ordenados por prazo, preservando a contagem total e evitando enviar toda a base ao navegador.

## Timeline, encaminhamentos e próxima ação

Cada demanda é um registro vivo: informações essenciais, um status simples e uma timeline cronológica de eventos (`demanda_eventos`) que é a única fonte de "o que aconteceu" — criação, atualizações, encaminhamentos, retornos, mudanças de responsável/prioridade/prazo/status, resolução, reabertura e encerramento.

Um encaminhamento é um único evento da timeline, sem status próprio: destino, setor, referência externa, descrição e prazo esperado, com anexos opcionais. "Registrar retorno" é uma ação independente — descreve o que foi recebido e pode opcionalmente referenciar o encaminhamento que originou a resposta, só para fechar o prazo esperado dele; nunca muda o status da demanda sozinha.

"Próxima ação" é um único campo (descrição, data e responsável) que indica o que precisa acontecer a seguir; concluí-la registra um evento na timeline. Ela alimenta as abas Para hoje e Atrasadas da Caixa de entrada e o job agendado de atenção.

## Timeline e anexos

Cada alteração relevante gera um evento imutável na timeline, com usuário, horário, tipo e metadados estruturados. Não há rotas para editar ou apagar a timeline. "Adicionar atualização" é o registro de texto livre interno ao gabinete, sempre um evento — não existe mais um conceito separado de observação/acompanhamento.

Os anexos aceitam PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, MP3 e M4A, em lotes de até cinco arquivos e no máximo 10 MB por arquivo. Extensão, MIME e tamanho são validados no servidor. Cada anexo pode se vincular ao evento da timeline que o originou; a tela de detalhe também reúne todos os anexos da demanda em uma visão consolidada, sem ser um fluxo próprio. A interface oferece seleção tradicional, arrastar e soltar, câmera em dispositivos compatíveis, prévia de imagens, remoção antes do envio e progresso.

Os arquivos ficam em `storage/app/private`, com nomes aleatórios. Downloads e prévias passam por autenticação, Policy e isolamento por gabinete; caminhos físicos e nomes internos nunca são enviados ao navegador. O serviço usa o Filesystem do Laravel e permite trocar o disco `local` por um compatível com S3.

## Relatórios e exportações

A rota /relatorios reúne indicadores por período, status, prioridade, origem, categoria, bairro e responsável. Também apresenta demandas atrasadas, resolvidas e encerradas, taxa e tempo médio de resolução, produtividade da equipe e encaminhamentos aguardando retorno.

Vereadores e chefes de gabinete podem combinar os filtros e solicitar arquivos PDF ou XLSX. Toda exportação é executada pela fila, inclusive para bases maiores, e registra solicitante, gabinete, parâmetros, andamento, falha e prazo de expiração. O PDF usa DomPDF; a planilha usa OpenSpout e contém as abas Resumo, Demandas, Produtividade e Encaminhamentos.

Os arquivos ficam no disco privado. O download exige autenticação, Policy, vínculo com o gabinete e exportação concluída ainda válida. Consultas executadas pelo worker aplicam explicitamente o gabinete_id, preservando o isolamento mesmo sem uma sessão HTTP ativa.

## Notificações, agenda e PWA

A rota `/agenda` oferece visualizações mensal, semanal, diária e em lista, com filtros por responsável, situação e tipo. Compromissos registram responsável, participantes internos, cidadão e demanda relacionados, local, período, dia inteiro, situação e recorrência diária, semanal ou mensal. Conflitos de horário do responsável ou dos participantes geram advertência sem bloquear o cadastro.

Cada gabinete possui timezone configurável; os horários são interpretados no fuso do gabinete e persistidos em UTC. Alterações de data cancelam lembretes pendentes e criam novos agendamentos. O cancelamento do compromisso também interrompe os lembretes ainda não executados.

Lembretes internos usam as notificações de banco do Laravel. O canal `whatsapp_simulado` permanece disponível para desenvolvimento e histórico, nunca realiza chamada externa e aparece como **Simulado**, não como enviado.

O canal `whatsapp` integra o [Gateway WhatsApp F3 Sistemas](https://whatsapp.f3sistemas.app.br) por API privada HMAC, sem acesso direto à Meta. Ele possui consentimento próprio, contatos cifrados, templates aprovados, outbox idempotente, callback assinado, supressão e fila exclusiva. O envio real nasce desativado e só pode ser habilitado após auditoria, consentimento e homologação controlada nos modos `PILOT` ou `LIVE`. O contrato funcional e operacional está em [`govnexgabwhatsapp.md`](govnexgabwhatsapp.md).

O scheduler despacha lembretes a cada minuto, prepara o resumo diário do WhatsApp e verifica prazos de demandas e encaminhamentos. A imagem de produção já sobe, via Supervisor, workers separados para `default`, `tse` e `whatsapp`, além de `php artisan schedule:work` (ver [`docker/production/supervisord.conf`](docker/production/supervisord.conf)); fora dela, garanta esses mesmos processos. As tentativas usam chave idempotente; resultados ambíguos são consultados antes de qualquer nova tentativa.

A central no cabeçalho permite abrir, marcar uma ou todas as notificações como lidas. Demandas atribuídas, mudanças de status, próxima ação atribuída/chegando/atrasada, prazos próximos e atrasos alimentam a central — atualizações de rotina não geram notificação a cada evento, só ocasionalmente quando pertinente.

O PWA inclui manifest, ícones, tela offline, cache somente de recursos estáticos públicos e aviso de atualização. Respostas autenticadas e dados pessoais não são armazenados para uso offline.

## Administração da plataforma

O administrador acessa uma área global separada em `/dashboard`, o diretório pesquisável de entidades em `/entidades` e a gestão cadastral em `/admin/gabinetes`. Fora de um contexto explícito, as rotas tenant rejeitam esse perfil. Ao escolher uma entidade e entrar deliberadamente em um gabinete, o menu muda para a operação daquele gabinete, com escopo isolado e entrada auditada, além de oferecer retorno claro à plataforma.

O painel resume gabinetes ativos e suspensos, usuários ativos, demandas totais e recentes, compromissos futuros e exportações recentes. A utilização básica compara usuários, cidadãos, demandas e demandas abertas por gabinete com consultas agregadas e limitadas.

A criação é dividida em duas etapas. Primeiro, o administrador cadastra a entidade e sua localização; em seguida, cadastra o primeiro gabinete, que herda município, UF e fuso, e define a conta responsável e os módulos. Novos gabinetes sempre partem de uma entidade existente e oferecem somente os tipos compatíveis com Câmara, Prefeitura ou gabinete independente. A gestão permite buscar e filtrar gabinetes, editar os dados institucionais e atualizar o responsável. A senha é obrigatória no primeiro cadastro, o e-mail de acesso é único e o backend define o perfil, o gabinete e a situação do usuário.

A suspensão preserva todos os dados e registra `suspended_at`, mas encerra a sessão e bloqueia o acesso operacional dos usuários daquele gabinete. A reativação remove o bloqueio. Não há exclusão de gabinete, cobrança automática nem estrutura comercial complexa no MVP.

## Banco, filas e storage

O desenvolvimento local usa o banco `gabinete_facil`. Sessões, cache e filas usam o driver de banco do Laravel. Em produção, o Supervisor da imagem oficial já executa `php artisan queue:work` para cada fila; ao rodar fora dela, mantenha isso manualmente.

As migrations incluem gabinetes, módulos por gabinete e sua auditoria, usuários, cidadãos, categorias, bairros, configurações, demandas, sequências de protocolo, timeline de eventos (`demanda_eventos`), anexos, exportações de relatórios, notificações, compromissos, participantes, lembretes, tentativas e as tabelas isoladas de consentimento, templates, outbox e callbacks do WhatsApp.

## Testes e qualidade

- `php artisan test` — suíte Pest
- `vendor/bin/phpstan analyse` — análise estática PHP
- `vendor/bin/pint --test` — estilo PHP
- `npm run types:check` — TypeScript
- `npm run lint:check` — ESLint
- `npm run format:check` — Prettier
- `npm run build` — build de produção

Os testes cobrem protocolo, isolamento entre gabinetes, métricas e filtros do dashboard, relatórios e exportações privadas, agenda, recorrência, conflitos, lembretes idempotentes, WhatsApp simulado e real com gateway falso, consentimento, HMAC, callbacks, Kanban, permissões de movimentação, encaminhamentos e retornos como eventos de timeline, vínculos seguros de documentos, próxima ação, resolução/encerramento/reabertura, validação e privacidade de anexos, auditoria detalhada e prevenção contra IDOR.

A consistência do design system também é verificada automaticamente: telas da aplicação não podem reintroduzir selects HTML nativos nem sobrescrever o raio oficial dos botões shadcn.

Antes de uma futura publicação, siga o [roteiro de homologação local por perfil](HOMOLOGATION.md). Ele separa as evidências automatizadas dos testes visuais que devem ser conferidos manualmente, sem executar deploy.

## Arquitetura e produção

O projeto é um monólito Laravel. Laravel concentra domínio, persistência, autenticação, autorização e transações; Inertia transporta props tipadas; React renderiza a interface. Services cuidam do protocolo, anexos, relatórios e auditoria, enquanto Actions coordenam criação, edição e mudanças de status.

As respostas recebem cabeçalhos defensivos contra MIME sniffing, framing e vazamento de referência. Áreas autenticadas desabilitam cache compartilhado, contas críticas não podem se autoexcluir e as rotinas agendadas evitam sobreposição. A interface de conta e os controles de teclado foram revisados em português e com atributos acessíveis.

Para produção, use o modelo [`.env.production.example`](.env.production.example) e siga o runbook de [deploy, filas, scheduler, backup e rollback](docs/DEPLOY.md). Configure HTTPS, cookies seguros, usuário MariaDB exclusivo, storage persistente ou S3, processos supervisionados e segredos fora do repositório.
