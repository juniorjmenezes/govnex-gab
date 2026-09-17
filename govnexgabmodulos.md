# Modularizacao por gabinete

Este documento define o contrato funcional dos modulos habilitaveis por gabinete. A implementacao permanece no monolito Laravel; os modulos controlam capacidades, rotas e processamentos, sem dividir o codigo em pacotes independentes.

## Catalogo

O nucleo e sempre ativo e inclui autenticacao, perfil, equipe, configuracoes, notificacoes internas e o dashboard adaptavel.

| Codigo | Capacidade | Dependencias |
| --- | --- | --- |
| `RELACIONAMENTO` | Cidadaos, bairros e localizacao | Nenhuma |
| `DEMANDAS` | Demandas, categorias, Kanban e colaboracao | Relacionamento |
| `ATENDIMENTOS` | Registros e historico de atendimentos | Relacionamento |
| `AGENDA` | Compromissos e lembretes | Relacionamento |
| `EVENTOS` | Eventos e participantes | Relacionamento |
| `POLITICA` | Painel politico, mapa, TSE e pesquisas | Nenhuma |
| `RELATORIOS` | Relatorios e exportacoes | Demandas |
| `WHATSAPP` | Notificacoes pelo gateway | Demandas, Agenda ou Politica |
| `BASE_CONHECIMENTO` | Biblioteca de PDFs para leitura da equipe | Nenhuma |

Cada finalidade do WhatsApp tambem exige seu modulo de origem. Configuracoes, templates, outbox e historico permanecem armazenados quando uma combinacao deixa de ser valida, mas novos envios ficam suspensos.

## Base de Conhecimento

Biblioteca de PDFs lidos dentro da plataforma (pdf.js, via `react-pdf`).

- **Biblioteca da plataforma:** documentos sem `gabinete_id`, mantidos pelo administrador em `/admin/conhecimento` e visiveis a todos os gabinetes com o modulo ativo.
- **Documentos do gabinete:** enviados por qualquer integrante e visiveis so para aquele gabinete. Removem quem enviou ou quem gerencia o gabinete.
- **Arquivos:** somente PDF (extensao, tipo e assinatura `%PDF-`), ate 30 MB, no disco privado `local` e servidos apenas por rota autenticada com Policy.
- **Progresso:** `conhecimento_leituras` guarda, por pessoa, as paginas vistas (a pagina conta depois de alguns instantes na tela) e a ultima pagina. Ao cobrir todas as paginas, a leitura e concluida.
- **Leituras completas:** `conhecimento_documentos.leituras_completas` conta quantas pessoas concluiram a leitura; cada pessoa conta uma unica vez, mesmo relendo.
- **Total de paginas:** informado pelo leitor na primeira abertura e fixado no documento; paginas acima dele sao recusadas.

A migration `2026_09_17_000001_create_base_conhecimento_tables.php` cria as tabelas e ativa o modulo para entidades e gabinetes ja existentes, sem duplicar linhas.

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

No cadastro de um gabinete, todos os modulos aparecem selecionados por padrao e podem ser ajustados antes da gravacao. Sincronizacao TSE exige `POLITICA`.

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
