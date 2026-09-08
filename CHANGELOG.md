# Changelog

Todas as mudanças relevantes de produto candidatas ao projeto principal serão documentadas neste arquivo.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto pretende adotar [Versionamento Semântico](https://semver.org/lang/pt-BR/) quando houver versões publicadas.

## [Unreleased]

- Fortalecida a importação manual do TSE com validação do nome e do contrato completo dos CSVs, limites contra ZIP bomb, pré-requisitos por eleição/UF, deduplicação atômica do despacho, cancelamento cooperativo e checksum também para arquivos retidos. O dataset de locais de votação agora reconhece o agregado nacional `eleitorado_local_votacao_YYYY.csv` e o processa em blocos, preservando coordenadas de fallback quando o TSE não as informa.
- Consolida o schema ainda não publicado: entidades, vínculo obrigatório dos gabinetes, timezone e colunas de pesquisas passam a nascer nas migrations primárias; migrations incrementais de cenário e nível de confiança foram removidas.

### Added

- Adicionada a fundação multientidade para gabinete independente, Câmara Municipal e Prefeitura, preservando `gabinete_id` como identificador técnico e os dados existentes.
- Adicionados vínculos contextuais por entidade e gabinete, liderança histórica, convites, contexto explícito e auditoria de entrada e redirecionamento legado.
- Adicionados planos comerciais versionados, licenças, cotas, módulos institucionais e identidade visual por entidade.
- Adicionados comunicados, solicitações institucionais com SLA, indicadores agregados, WhatsApp por entidade e transferência auditada de gabinetes.
- Adicionadas referências territoriais compartilháveis sem compartilhar cidadãos ou demais dados privados dos gabinetes.
- Adicionado cadastro administrativo dos três modelos, com tipos de gabinete compatíveis e inclusão em Câmara ou Prefeitura existente.
- Exibido nas configurações do gabinete o número eleitoral definido pela administração da plataforma, sem permitir edição pelo vereador.
- Adicionada modularização por gabinete para Relacionamento, Demandas, Atendimentos, Agenda, Eventos, Política, Relatórios e WhatsApp, com catálogo central, dependências e auditoria imutável.
- Adicionada gestão exclusiva pelo administrador da plataforma, com seleção no cadastro, alteração transacional e histórico por gabinete.
- Adicionada proteção por middleware, navegação adaptável, dashboard independente de Demandas e página específica para módulos desativados.
- Adicionada suspensão auditável de relatórios, lembretes, sincronizações TSE e novos envios WhatsApp quando o módulo de origem fica indisponível.
- Adicionada integração transacional com o Gateway WhatsApp F3 Sistemas, com consentimento específico, contatos cifrados, templates, outbox, callback assinado e fila dedicada.
- Adicionada administração por gabinete nos modos `OFF`, `PILOT` e `LIVE`, mantendo envio real desligado por padrão.
- Adicionados eventos operacionais de demandas, agenda, resumo diário e pesquisas eleitorais, sem conteúdo sensível nas mensagens.
- Adicionada imagem de produção multi-stage para PHP 8.4, Apache, Composer e assets Vite.
- Adicionado bootstrap demonstrativo protegido, com credenciais únicas e saída restrita.
- Separadas as sincronizações TSE em uma fila e um worker dedicados.
- Adicionados controles de proxy confiável e configuração de produção documentada.
- Adicionado ensaio administrativo idempotente para validar, em lotes, os oito templates operacionais com manifesto privado e interrupção automática diante de falhas.
- Adicionado o Mapa de eleitores oficial, baseado nos dados do TSE da eleição municipal de 2024 (votação por seção e locais de votação), com mapa de calor por local de votação para o candidato titular do gabinete e rotina de geocodificação de fallback quando o TSE não informa coordenadas. O mapa por endereço cadastrado pelo gabinete foi renomeado para "Mapa de prospecção" e os dois passaram a compartilhar o menu "Mapas".
- Evoluído o Mapa de eleitores com alternância entre visualização em calor e em círculos proporcionais (escala de raiz quadrada), métrica selecionável entre votos e % dos votos (Desempenho no local já preparado na interface, aguardando a sincronização de votos de todos os candidatos por seção para ser habilitado), indicador de cobertura de georreferenciamento, popup com percentual e seções eleitorais do local, ranking com barra proporcional sincronizado ao mapa (clique centraliza/dá zoom/abre o popup; hover destaca o ponto) e painel lateral responsivo via drawer em telas pequenas.
- Adicionada a timeline unificada da demanda (`demanda_eventos`): toda ação relevante (criação, atualização, encaminhamento, retorno, mudança de responsável/prioridade/prazo/status, resolução, reabertura, encerramento) passa a gerar um único tipo de evento cronológico, substituindo o histórico separado, as observações e o sub-workflow de encaminhamentos.
- Adicionada a "Próxima ação" (descrição, data e responsável) como campo único da demanda, alimentando as abas "Para hoje" e "Atrasadas" da nova Caixa de entrada e o job agendado de atenção.
- Adicionado "Registrar retorno" como ação independente para registrar a resposta recebida de um encaminhamento, sem depender de reabrir ou reenviar o encaminhamento original.
- Adicionado o conceito de Resultado (Atendida, Parcialmente atendida, Não atendida, Orientação prestada, Encaminhada definitivamente, Duplicada, Outra) como qualificação opcional e não destrutiva da resolução — não é um status.
- Adicionada a Caixa de entrada como tela principal de demandas, com abas Caixa de entrada, Minhas, Aguardando, Para hoje e Atrasadas, substituindo a grade administrativa como ponto de entrada padrão.

### Changed

- Consolidado o gerenciamento de acessos em `entidade_membros` e `gabinete_membros`: convites agora respeitam a hierarquia de papéis, a navegação legada preserva o gabinete explicitamente selecionado, a conta permanece global quando um gabinete é suspenso e a remoção da equipe desativa somente o vínculo local.
- A identidade visual operacional agora prioriza a cor escolhida pelo gabinete, usando a cor da entidade e depois a do sistema apenas como fallback.
- Padronizados os fundos decorativos de ícones em cards, cabeçalhos, categorias, segurança e identidade visual, preservando avatares e indicadores deliberadamente circulares.
- O acesso multi-entidade passou a seguir uma hierarquia explícita: o diretório pesquisável e paginado abre a entidade, a entidade oferece busca e paginação dos gabinetes e a entrada em um gabinete carrega sua própria visão geral, inclusive para o administrador da plataforma em contexto auditado.
- Renomeada a nomenclatura de "Organização" para "Entidade" e de "Unidade" para "Gabinete" em toda a base (tabelas, models, controllers, services, enums, rotas e frontend), refletindo que o conceito não se limita a gabinetes parlamentares — também cobre setores administrativos, prefeituras e câmaras.
- Separada a criação administrativa em duas etapas: primeiro a entidade, com tipo e localização; depois o gabinete, que herda município, UF e fuso e cadastra sua conta responsável e módulos. Atalhos contextuais conectam o diretório, a entidade e a gestão de gabinetes.
- Padronizados os cabeçalhos dos cards nos cadastros de entidades e gabinetes, sem numeração artificial das seções.
- Textos explicativos de campos passam a ser exibidos em popovers acessíveis acionados por um ícone de ajuda alinhado à label, mostrando somente a orientação sem repetir o rótulo e reduzindo a altura dos formulários.
- A seleção de módulos do gabinete passa a usar a mesma grade de cartões com switches adotada pelos módulos da entidade.
- O design system global passa a usar o preset shadcn `b7D49K45A` (`radix-sera`, tema `orange`), incluindo componentes e tokens oficiais, com a cor neutra trocada de `mist` para `stone`. A tipografia global foi trocada de Outfit para Geist Variable (corpo e títulos) e Geist Mono Variable (monoespaçada), aproximando a estrutura visual da sidebar/header de um shell de referência do Shadcn Studio: badges numéricos nos itens de navegação, busca rápida no header e indicador de status no avatar do usuário.
- Os cabeçalhos das páginas administrativas deixam de repetir um rótulo em caixa alta antes do título e do subtítulo.
- Textos auxiliares exibidos abaixo do conteúdo principal nas células das tabelas passam a usar globalmente `text-xs text-muted-foreground`.
- A listagem administrativa de gabinetes foi simplificada e ganhou um modal de detalhes para titular, último acesso, usuários, cidadãos, demandas e situação dos dados políticos.
- A sincronização política iniciada pelo painel agora cria um job independente por etapa na fila de banco e exibe acompanhamento automático com barra de progresso para cada fonte selecionada.
- Isolada a suíte automatizada do ambiente do container web com bootstrap próprio, SQLite em memória e configurações de teste forçadas.
- Isolada a sincronização de templates pela conta WhatsApp configurada, com recriação segura de versões após mudança de WABA e sem ativação automática.
- Mantida a finalidade de pesquisa eleitoral fora dos templates operacionais até existir consentimento de marketing próprio.
- Alinhado o runtime mínimo a PHP 8.4.1 para manter compatibilidade com o lockfile e o OpenSpout 5.8.
- O painel político passou a preferir a média própria (`MediaCalculator`), calculada a partir do histórico de pesquisas individuais já sincronizado, sempre que ela cobrir mais de uma pesquisa; caso contrário (inclusive presidente, cuja fonte nunca publica detalhamento por candidato), continua usando `/averages` do ElectioLab. A interface indica a origem de cada média exibida.
- O upload manual continua como fluxo principal dos dados do TSE, mas a administração pode acionar um fallback sob demanda que baixa o ZIP oficial e reutiliza o mesmo pipeline de validação e processamento. A interface alerta que o TSE pode bloquear a tentativa com HTTP 403; não há mirror, cache nem agendamento automático. PollingData permanece independente.
- Adicionado o dataset manual `poll_registry`, que cruza o registro oficial de pesquisas eleitorais do TSE com pesquisas já sincronizadas e preenche `registro_tse` somente quando ainda vazio.
- Preparada a publicação em `https://gabnex.f3sistemas.app.br` pela infraestrutura da `f3-vps`.
- Ampliado somente o timeout administrativo de templates para permitir reconciliação segura sem afetar o envio de mensagens.
- Propagado o estado de submissão ambígua pelo contrato privado, bloqueando novo envio até a sincronização com a Meta.
- Ajustado o lembrete de agenda ao cidadão para não terminar efetivamente em uma variável, conforme validação da Meta.
- Homologado o modo `LIVE` com 40 notificações únicas para cinco contatos controlados: todas alcançaram ao menos `DELIVERED`, 21 chegaram a `READ` na conferência final e a reexecução não produziu duplicações.
- Simplificada a máquina de status da demanda para cinco estados (Nova, Em andamento, Aguardando, Resolvida, Encerrada) com transições diretas permissivas; sair de Resolvida/Encerrada passou a ser sempre uma reabertura explícita e auditada, nunca uma transição simples.
- O Kanban de demandas e as notificações (interna e WhatsApp) passaram a reaproveitar a mesma ação de domínio de transição de status usada no restante da aplicação, sem lógica duplicada.
- Renomeado o produto de "Gabnex" para "GOVNEX GAB": textos visíveis, `APP_NAME`, manifesto PWA, comandos artisan (`gabnex:*` → `govnexgab:*`), config `gabnex.php` → `govnexgab.php`, User-Agent das integrações externas (TSE, geocoding, PollingData), texto de consentimento do WhatsApp (versão `1.0` → `1.1`) e demais identificadores internos. O identificador `meta_name` dos templates do WhatsApp foi deliberadamente mantido (`gabnex_..._v1`), pois já está aprovado na API do WhatsApp Business e trocá-lo interromperia o envio até nova aprovação. Domínio de produção, paths e scripts da VPS (`f3-infra`) não foram alterados — dependem de uma migração de infraestrutura separada.

### Removed

- Descontinuados os módulos de Comunicação institucional, Solicitações institucionais (com SLA) e Indicadores institucionais, incluindo o arquivamento imutável de histórico institucional na transferência de gabinete (`gabinete_transferencia_acervos`), que dependia exclusivamente desses módulos. A transferência de gabinete em si não foi afetada.
- Removidos os status Em análise, Encaminhada, Aguardando resposta, Não resolvida e Arquivada, o sub-workflow próprio de encaminhamento (situação, resposta e prazo em `encaminhamentos`), as observações internas como conceito operacional separado e a sugestão automática de status (`SuggestDemandStatus`) — todos absorvidos pela timeline unificada, pela "Próxima ação" e pelas ações dedicadas de resolver/encerrar/reabrir. Arquivamento deixou de ser um status: demandas encerradas seguem visíveis e a exclusão (reservada a vereador/chefe de gabinete) continua preservando o histórico via soft delete.

### Fixed

- Corrigido o formulário de novo gabinete para exibir o estado vazio somente quando não houver entidades e manter a ação de criar entidade alinhada ao seletor.
- Corrigida a importação manual de datasets do TSE, separando a base permanente Município TSE/IBGE dos conjuntos anuais, ampliando o limite para 500 MB, alinhando os limites HTTP, usando toda a largura disponível na barra de progresso e validando CSV, colunas, ano e UF antes de criar o job.
- Uploads manuais do TSE agora exigem ao menos um gabinete ativo com o módulo Política.
- Corrigida a imagem de produção, que só executava o Apache e deixava sincronizações do TSE/ElectioLab, envio de WhatsApp, exportações de relatório e lembretes agendados presos em "pendente" para sempre. O container agora usa Supervisor para manter, junto do Apache, o scheduler (`schedule:work`) e os workers das filas `default`, `tse` e `whatsapp`.
- Sincronizações PollingData órfãs agora são encerradas antes de uma nova tentativa e importações TSE sem registros deixam de ser marcadas como concluídas.

### Security

- Escritas contextuais agora dependem de entidade e gabinete validados no backend; gestores institucionais não herdam acesso aos dados privados dos gabinetes.
- Atualizados `js-yaml` para 4.3.1 e `nanoid` para 3.3.18 no lockfile, eliminando duas vulnerabilidades altas em dependências transitivas.
- Adicionadas idempotência, proteção contra replay, transições monotônicas, reconciliação de timeout e expurgo de dados sensíveis do WhatsApp.
- Atualizadas dependências frontend transitivas para eliminar os alertas conhecidos pelo `npm audit`.
