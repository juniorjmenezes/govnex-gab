# Modularizacao por gabinete

Este documento define o contrato funcional dos modulos habilitaveis por gabinete. A implementacao permanece no monolito Laravel; os modulos controlam capacidades, rotas e processamentos, sem dividir o codigo em pacotes independentes.

## Catalogo

O nucleo e sempre ativo e inclui autenticacao, perfil, equipe, configuracoes, notificacoes internas e o dashboard adaptavel.

| Codigo | Capacidade | Dependencias |
| --- | --- | --- |
| `RELACIONAMENTO` | Cidadaos, bairros e localizacao | Nenhuma |
| `DEMANDAS` | Demandas, categorias, Kanban e colaboracao | Relacionamento |
| `ATENDIMENTOS` | Registros e historico de atendimentos | Relacionamento |
| `AGENDA` | Compromissos e lembretes | Relacionamento (exceto onde o tipo nao oferece Relacionamento) |
| `EVENTOS` | Eventos e participantes | Relacionamento |
| `POLITICA` | Painel politico, mapa, TSE e pesquisas | Nenhuma |
| `RELATORIOS` | Relatorios e exportacoes | Demandas |
| `WHATSAPP` | Notificacoes pelo gateway | Demandas, Agenda ou Politica |
| `BASE_CONHECIMENTO` | Biblioteca de PDFs para leitura da equipe | Nenhuma |
| `SECRETARIA_LEGISLATIVA` | Gestao dos recursos compartilhados entre os gabinetes da Camara | Agenda e Base de Conhecimento |

Cada finalidade do WhatsApp tambem exige seu modulo de origem. Configuracoes, templates, outbox e historico permanecem armazenados quando uma combinacao deixa de ser valida, mas novos envios ficam suspensos.

## Disponibilidade por tipo de gabinete

Nem todo tipo de gabinete pode ativar todo modulo (`GabineteModule::availableFor()`). Um modulo indisponivel para o tipo nao aparece no catalogo administrativo daquele gabinete, nao entra no conjunto inicial (`GabineteModuleManager::allEnabled($tipo)`, usado por `EstruturaProvisioningService::criarGabinete()`) e e recusado por `GabineteModuleManager::sync()` com "Este tipo de gabinete nao aceita: ...". Se o gabinete tiver uma linha anterior a restricao, o proximo `sync()` a desliga (com evento `DESATIVADO`), sem apagar dados.

| Tipo de gabinete | Modulos disponiveis |
| --- | --- |
| Gabinete independente, de vereador, do prefeito, Secretaria, Setor administrativo | Todos, exceto `SECRETARIA_LEGISLATIVA` |
| `SECRETARIA_LEGISLATIVA` | Somente `AGENDA`, `WHATSAPP`, `BASE_CONHECIMENTO` e `SECRETARIA_LEGISLATIVA` |

A Secretaria legislativa nao atende cidadao nem faz trabalho politico: `RELACIONAMENTO`, `DEMANDAS`, `ATENDIMENTOS`, `EVENTOS`, `POLITICA` e `RELATORIOS` ficam indisponiveis para ela, inclusive como opcao para um administrador ligar depois. As dependencias levam o tipo em conta (`GabineteModuleCatalog::dependencies($modulo, $tipo)`):

- **Agenda sem Relacionamento:** o vinculo do compromisso com cidadao e opcional (`compromissos.cidadao_id` nulo), entao a dependencia e de produto, nao tecnica. Ela continua valendo para todo tipo que oferece Relacionamento; so deixa de existir onde Relacionamento e indisponivel.
- **WhatsApp:** a exigencia continua sendo "ao menos um" de Demandas, Agenda ou Politica, filtrada pelo que o tipo aceita — na Secretaria, a Agenda.

A restricao e so de gabinete: no nivel entidade, a Camara continua contratando o catalogo inteiro, porque os gabinetes de vereador dela usam todos os modulos.

## Base de Conhecimento

Biblioteca de PDFs lidos dentro da plataforma (pdf.js, via `react-pdf`).

- **Tres niveis (`HasTieredVisibility`):** `gabinete_id` preenchido = do gabinete; `gabinete_id` nulo com `entidade_id` preenchido = compartilhado com a entidade; ambos nulos = plataforma. O documento de gabinete tambem guarda o `entidade_id` do gabinete, so para consistencia e auditoria — isso nao amplia a visibilidade.
- **Biblioteca da plataforma:** documentos sem `gabinete_id` e sem `entidade_id`, mantidos pelo administrador em `/admin/conhecimento` e visiveis a todos os gabinetes com o modulo ativo.
- **Documentos do gabinete:** enviados por qualquer integrante e visiveis so para aquele gabinete. Removem quem enviou ou quem gerencia o gabinete.
- **Compartilhados com a Camara:** ver "Documentos institucionais (Fase 2)" em Secretaria Legislativa.
- **Arquivos:** somente PDF (extensao, tipo e assinatura `%PDF-`), ate 30 MB, no disco privado `local` e servidos apenas por rota autenticada com Policy.
- **Progresso:** `conhecimento_leituras` guarda, por pessoa, as paginas vistas (a pagina conta depois de alguns instantes na tela) e a ultima pagina. Ao cobrir todas as paginas, a leitura e concluida.
- **Leituras completas:** `conhecimento_documentos.leituras_completas` conta quantas pessoas concluiram a leitura; cada pessoa conta uma unica vez, mesmo relendo.
- **Total de paginas:** informado pelo leitor na primeira abertura e fixado no documento; paginas acima dele sao recusadas.

A migration `2026_09_17_000001_create_base_conhecimento_tables.php` cria as tabelas e ativa o modulo para entidades e gabinetes ja existentes, sem duplicar linhas.

## Secretaria Legislativa

Gabinete especial (`tipo_gabinete = SECRETARIA_LEGISLATIVA`, titular "Secretario legislativo") dentro de uma Camara Municipal, que coordena recursos compartilhados entre os gabinetes de vereador da mesma Camara. A Fase 0 entrega so a fundacao; agenda mutua, documentos institucionais, comissoes e WhatsApp institucional chegam nas fases seguintes.

- **Tipo de gabinete:** aceito somente em `CAMARA_MUNICIPAL`, no maximo um por Camara. A trava fica em `EstruturaProvisioningService::criarGabinete()`, com a entidade bloqueada (`lockForUpdate`); gabinete excluido (soft delete) tambem conta. O titular e `ADMINISTRADOR` do gabinete, como qualquer outro: nao ha papel de acesso novo.
- **Nivel entidade:** so Camaras contratam o modulo. Ele nasce contratado e **desligado** (`contratado = true`, `ativo = false`), tanto no provisionamento de entidades novas quanto no backfill de `2026_10_14_000001_enable_secretaria_legislativa_module.php`. Ativar exige Agenda e Base de Conhecimento ativos na entidade.
- **Nivel gabinete:** so o gabinete do tipo Secretaria legislativa pode ativar o modulo (`GabineteModule::availableFor()`); para os demais tipos ele nao aparece no catalogo administrativo e `GabineteModuleManager::sync()` o recusa. Ativar exige Agenda e Base de Conhecimento no gabinete. O proprio gabinete Secretaria so aceita Agenda, WhatsApp, Base de Conhecimento e este modulo (ver "Disponibilidade por tipo de gabinete").
- **Isolamento:** o modulo nao da a Secretaria acesso a dados privados dos gabinetes de vereador (cidadaos, demandas etc.). Os dados compartilhados futuros usam `App\Models\Concerns\HasTieredVisibility`: `gabinete_id` preenchido e privado do gabinete; `gabinete_id` nulo com `entidade_id` preenchido e compartilhado com a entidade; ambos nulos e da plataforma. A visibilidade passiva pelos vereadores dependera do modulo ativo na entidade mais o modulo-base (Agenda/Base de Conhecimento) do proprio gabinete.
- **Govnex Hub:** o Hub ainda nao envia um tipo de unidade que se traduza em Secretaria legislativa (`HubTipoMapper`). Ate isso existir, o provisionamento automatico nao cria esse gabinete.
- **Agenda institucional (Fase 1):** `CompromissoInstitucional` (tabela `compromissos_institucionais`, migration `2026_10_14_000002`) pertence a entidade (`entidade_id`) e guarda quem publicou (`criado_por_gabinete_id`). O publico vem de `compromisso_institucional_gabinetes`: sem linha, todos os gabinetes de vereador da Camara; com linhas, so os listados (apenas gabinetes de vereador ativos da mesma Camara). Os itens aparecem na agenda de cada gabinete (`AppointmentController::index`, `source = institutional`, selo "Secretaria legislativa") quando o modulo esta ativo na entidade e a Agenda no gabinete; o filtro por integrante os oculta. A escrita (`agenda/institucional/*`, `InstitutionalAppointmentController`) fica sob `module:SECRETARIA_LEGISLATIVA`, e a `CompromissoInstitucionalPolicy` exige gabinete do tipo Secretaria, perfil com escrita e, para alterar ou excluir, ter publicado o compromisso. Sem recorrencia nem lembretes nesta fase. O model nao usa `HasTieredVisibility`: nao ha nivel privado de gabinete nem de plataforma, e o recorte de publico e a tabela de alvos.
- **Documentos institucionais (Fase 2):** `ConhecimentoDocumento` usa `HasTieredVisibility` (coluna `conhecimento_documentos.entidade_id`, incorporada a migration `2026_09_17_000001`). No envio pela Base de Conhecimento, o interruptor "Compartilhar com a Camara" (`compartilhar_entidade`) grava `gabinete_id` nulo e o `entidade_id` do gabinete; ele so aparece e so e aceito com `ConhecimentoDocumentoPolicy::createEntidadeWide()` — gabinete do tipo Secretaria, perfil com escrita e o modulo ativo no gabinete (e, por isso, na entidade). Desligado por padrao: nada sai do gabinete sem acao explicita. Os gabinetes da mesma Camara veem esses documentos com o selo "Secretaria legislativa" enquanto o modulo estiver ativo na entidade (`KnowledgeBaseController::index` passa o `entidade_id` a `visibleTo()` so nesse caso; a Policy aplica a mesma regra a leitura e ao arquivo). Outra Camara nunca ve. Removem so quem enviou ou quem gerencia a Secretaria da mesma Camara, ainda no contexto dela e com o modulo ativo. A biblioteca da plataforma em `/admin/conhecimento` nao lista nem abre esses documentos.
- **Comissoes legislativas (Fase 3):** tabelas `comissoes_legislativas`, `comissao_membros` e `comissao_documentos` (migration `2026_10_14_000003`). `ComissaoLegislativa` pertence a entidade (`entidade_id`, sem `BelongsToGabinete`, como `EntidadeBairro`); tipo, situacao e cargo sao enums (`CommitteeType` permanente/temporaria, `CommitteeStatus` ativa/extinta, `CommitteeRole` presidente/relator/membro). O membro (`ComissaoMembro`) aponta para o gabinete de vereador de origem (referencia, nao posse) e, opcionalmente, para a pessoa indicada do gabinete (`usuario_id`, nula = titular); a pessoa e o cargo mudam sem duplicar a linha (`unique(comissao_id, gabinete_id)`). `CommitteeMembershipService` garante, fora da camada HTTP: gabinete de vereador ativo da mesma Camara, pessoa com vinculo ativo no gabinete, um presidente por comissao e nenhum membro novo em comissao extinta. Extinguir preenche `encerrada_em` e o `saiu_em` dos membros; reativar os devolve. Documentos nao tem arquivo proprio: `comissao_documentos` liga a comissao a um `ConhecimentoDocumento` ja compartilhado com a mesma Camara (`gabinete_id` nulo, mesmo `entidade_id`) — documento privado de gabinete ou da plataforma e recusado (`unique(comissao_id, documento_id)`). Gate assimetrico: leitura (`comissoes`, `comissoes/{comissao}`) para qualquer gabinete da Camara, sem modulo no gabinete, com o modulo ativo na entidade (`ComissaoLegislativaPolicy::viewAny`/`view`); gestao (cadastro, edicao, exclusao, membros, documentos) sob `module:SECRETARIA_LEGISLATIVA` e Policy de Secretaria com escrita da mesma Camara. Outra Camara recebe 403. O item "Comissoes" da sidebar depende so do modulo na entidade.

## Regras de estado

- `gabinete_modulos` e a fonte de verdade. Ausencia de registro significa modulo desativado.
- `gabinete_modulo_eventos` registra ativacoes e desativacoes de forma imutavel e sem dados sensiveis.
- Somente `administrador_plataforma` pode substituir a lista completa de modulos.
- Dependencias invalidas retornam erro explicativo; o sistema nunca ativa ou desativa dependencias silenciosamente.
- Desativar um modulo nao remove dados, arquivos, configuracoes nem historico.
- Reativar um modulo devolve o acesso aos dados preservados.
- O cache de modulos existe somente durante a requisicao e e invalidado apos alteracoes.

## Protecao em profundidade

A sidebar usa `auth.modules` para ocultar entradas, mas a autorizacao nao depende da interface. Os grupos tenant usam o middleware `module:<CODIGO>` e retornam HTTP 403 com uma pagina Inertia especifica quando o modulo esta desativado.

Form Requests rejeitam IDs e campos de modulos indisponiveis. O dashboard evita consultas a recursos desativados. Jobs verificam o modulo ao serem despachados e novamente antes de gerar arquivos, sincronizar dados ou produzir efeitos externos.

Callbacks do WhatsApp continuam acessiveis para reconciliar entregas e opt-out. Somente novos envios sao suprimidos. Jobs ja aceitos externamente nao sao repetidos; o sistema conserva seu estado para reconciliacao.

## Administracao

No cadastro de um gabinete, todos os modulos que o tipo aceita aparecem selecionados por padrao e podem ser ajustados antes da gravacao. Sincronizacao TSE exige `POLITICA`.

No detalhe administrativo, o operador visualiza dependencias, substitui a selecao completa e consulta o historico. A alteracao ocorre em transacao e nao produz estado parcial quando a combinacao e invalida.

## Banco e compatibilidade

A migration `2026_08_10_000001_create_gabinete_module_tables.php` cria as duas tabelas de controle e faz backfill de todos os modulos ativos para gabinetes existentes. Assim, a primeira publicacao preserva o comportamento atual.

O cadastro administrativo e o `DatabaseSeeder` tambem gravam explicitamente a selecao inicial. Uma instalacao limpa, em que os gabinetes demonstrativos sao criados depois da migration, inicia com o catalogo completo habilitado.

A migration deve ser testada em banco descartavel e aplicada uma unica vez apos backup. Ela nao apaga dados de negocio e pode ser reaplicada sem duplicar o backfill.

## Homologacao

Validar pelo menos dois gabinetes simultaneamente:

1. um gabinete com todos os modulos ativos;
2. um gabinete com somente nucleo e Relacionamento;
3. acesso direto a uma rota desativada retornando 403;
4. dashboard, sidebar e formularios sem campos cruzados;
5. relatorio, TSE, lembrete e WhatsApp pendentes sendo cancelados ou suprimidos antes de efeitos externos;
6. callback WhatsApp continuando idempotente;
7. reativacao devolvendo acesso aos dados historicos.

## Publicacao e rollback

Esta implementacao nao foi aplicada em producao. Uma publicacao futura exige o runbook de `docs/DEPLOY.md`, backup do banco e validacao de que o backfill deixou todos os gabinetes com todos os modulos do catalogo ativos.

Em rollback de codigo, as tabelas podem permanecer no banco sem uso. Nao apague as tabelas nem os eventos durante a estabilizacao. Se a release anterior for restaurada, ela continuara operando sem consultar a nova camada.
