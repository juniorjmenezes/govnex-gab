# Evolução multientidade do GOVNEX GAB

## Estado e objetivo

Este documento é a referência funcional e técnica para transformar o GOVNEX GAB em uma única plataforma multientidade, sem reescrever os módulos atuais nem romper o isolamento por gabinete.

Modelos atendidos:

- `GABINETE_INDEPENDENTE`: uma entidade com um único gabinete e interface simplificada;
- `CAMARA_MUNICIPAL`: entidade com gabinetes parlamentares e setores administrativos;
- `PREFEITURA`: entidade com gabinete do prefeito, secretarias e setores administrativos.

A hierarquia é deliberadamente limitada a `entidade → gabinete → equipe`. A tabela técnica `gabinetes` continua sendo a chave dos dados operacionais existentes.

## Estado da implementação local

A implementação candidata está aplicada localmente, ainda sem deploy em produção:

- fundação multientidade, vínculos, lideranças, convites e contexto explícito;
- licenças, cotas e módulos hierárquicos;
- identidade visual e referências territoriais compartilhadas;
- WhatsApp por entidade e transferência de gabinetes;
- cadastro administrativo dos três modelos, com criação de nova entidade ou inclusão em Câmara/Prefeitura existente;
- redirecionamento auditado das URLs legadas para o contexto canônico.

Todos os dados atuais permanecem vinculados a `gabinete_id`. A compatibilidade ainda é intencional e sua retirada depende dos critérios deste documento.

## Invariantes de segurança

- Toda operação de escrita deve receber e validar entidade e gabinete no backend.
- `users.gabinete_id` e `users.role` são compatibilidade temporária, nunca a única autorização de uma rota contextual.
- O administrador da plataforma pode entrar em qualquer contexto, mas cada entrada e troca fica auditada.
- Gestores da entidade não recebem acesso automático a cidadãos, demandas, observações, anexos ou documentos privados dos gabinetes.
- Dados privados permanecem associados a `gabinete_id`; nenhuma desativação, expiração ou transferência apaga dados.
- URLs contextuais são canônicas. URLs legadas podem redirecionar apenas após resolver um vínculo inequívoco.
- Módulos, licença, cotas e permissões são verificados no backend antes de criar jobs ou efeitos externos.
- WhatsApp interno e notificações internas são independentes; não existe fallback automático entre contas.

## Fundação de dados

### Entidade e gabinete

`entidades` é o tenant raiz. Contém tipo, identidade, jurisdição, fuso, marca e estado. `gabinetes` recebe `entidade_id` e `tipo_gabinete`.

Tipos de gabinete:

- `GABINETE_INDEPENDENTE`;
- `GABINETE_VEREADOR`;
- `GABINETE_PREFEITO`;
- `SECRETARIA`;
- `SETOR_ADMINISTRATIVO`.

O nome é livre. Rótulos de líder são contextuais: Vereador, Prefeito, Secretário ou Responsável.

### Vínculos e liderança

Papéis da entidade:

- `ADMINISTRADOR`;
- `GESTOR`;
- `OPERADOR`;
- `AUDITOR`.

Papéis do gabinete:

- `LIDER`;
- `GESTOR`;
- `MEMBRO`.

Um usuário pode possuir vários vínculos. Períodos de liderança são históricos e o gabinete permanece estável quando o agente político muda.

Mapeamento do backfill para gabinetes independentes existentes:

- Vereador atual: administrador da entidade e líder do gabinete;
- Chefe de gabinete: gestor da entidade e do gabinete;
- Assessor: operador da entidade e membro do gabinete;
- Administrador da plataforma: acesso global sem vínculo artificial.

Em novas Câmaras e Prefeituras, a liderança criada com o gabinete nasce como `OPERADOR` da entidade e `LIDER` do gabinete. Papéis institucionais elevados são atribuídos explicitamente por convite e não são rebaixados por atualizações dos campos legados do usuário.

Convites usam token de uso único, validade e aceite por e-mail. A contingência por senha temporária é auditada e exige troca no primeiro acesso.

### Backfill invisível

Cada gabinete existente origina uma entidade `GABINETE_INDEPENDENTE` com um gabinete. O backfill é idempotente e preserva IDs, slugs, usuários, módulos e dados de negócio.

## Contexto e autorização

Rotas canônicas:

```text
/entidades/{entidade}/...
/entidades/{entidade}/gabinetes/{gabinete}/...
```

`EntidadeContext` resolve a entidade. `GabineteContext` recebe um gabinete explicitamente validado. Models operacionais continuam filtrados por `gabinete_id`.

O contexto efetivo exige:

1. usuário ativo;
2. entidade e gabinete ativos;
3. vínculo compatível ou administrador da plataforma;
4. gabinete pertencente à entidade;
5. módulo disponível e permissão para a ação.

Entradas e trocas de contexto registram apenas IDs, rota, resultado e hashes sanitizados de rede/agente.

## Módulos, planos e cotas

Escopos do catálogo:

- `ORGANIZACAO` (entidade);
- `UNIDADE` (gabinete);
- `AMBOS`.

A disponibilidade efetiva exige, nesta ordem:

1. módulo presente na versão imutável do plano ou em ajuste explícito;
2. licença vigente;
3. ativação pela entidade;
4. distribuição para o gabinete, quando aplicável;
5. dependências satisfeitas;
6. cota disponível para novas ações mensuradas.

Planos e versões são imutáveis depois de utilizados. A licença guarda um retrato das cotas. Ajustes por entidade são auditados.

Cotas bloqueantes:

- gabinetes ativos;
- usuários ativos;
- armazenamento;
- mensagens WhatsApp no mês.

Exceder cota bloqueia apenas a criação relacionada; consulta, exportação e regularização permanecem disponíveis.

## Referências compartilhadas

Município, bairros institucionais e datasets públicos podem ser compartilhados pela entidade. Cidadãos, favoritos e rotinas internas permanecem privados por gabinete.

A adoção de bairros institucionais é gradual. A migration cria uma referência por entidade e vincula os bairros locais equivalentes, sem mover cidadãos ou apagar registros. Novos gabinetes podem importar uma referência compartilhada, mantendo seus dados privados isolados.

## WhatsApp

Cada entidade pode usar conta própria ou conta central explicitamente atribuída. Não existe seleção implícita. O administrador da plataforma associa contas retornadas por uma consulta HMAC somente leitura do cliente técnico `GABINETE`.

Conexões, templates e consumo são vinculados à entidade e à conta. Uma falha de WhatsApp nunca impede a notificação interna.

## Transferência de gabinete

Transferências exigem:

- mesma cidade e estado;
- aceite da origem e do destino;
- aprovação do administrador da plataforma;
- janela de execução e manifesto auditável.

Os dados privados acompanham o gabinete. Módulos são recalculados pela licença de destino e o WhatsApp fica desativado até nova associação explícita.

## Fases e gates de entrega

1. Fundação, vínculos, liderança, convites, contexto e backfill invisível: implementada localmente.
2. Planos, cotas, módulos hierárquicos, identidade visual e referências compartilhadas: implementada localmente.
3. WhatsApp por entidade e consumo: implementada localmente, sem chamada real.
4. Transferências: implementada localmente; retirada da compatibilidade legada permanece futura.

Comunicados institucionais, solicitações institucionais com SLA e indicadores institucionais foram implementados e posteriormente descontinuados; não fazem mais parte do escopo.

Antes de promover qualquer fase, as migrations devem ser executadas duas vezes e revertidas em banco descartável MariaDB 11.4, com testes de isolamento, changelog e revisão própria. Publicação exige autorização separada, backup, restauração de prova, clone de produção, smoke test e rollback documentado.

## Critérios para retirar o legado

`users.gabinete_id`, `users.role` e as URLs sem contexto só poderão ser removidos quando:

- nenhum consumidor de código ou integração os utilizar;
- todos os usuários possuírem vínculos equivalentes;
- a telemetria mostrar ausência de URLs legadas por período definido;
- exportações, filas, notificações e callbacks usarem contexto explícito;
- migration e rollback tiverem sido ensaiados em cópia da produção.

## Fora de escopo

- cobrança automática;
- formulários institucionais dinâmicos;
- domínio personalizado por entidade;
- compartilhamento de cidadãos entre gabinetes;
- mais de um nível entre entidade e gabinete;
- remoção imediata das colunas e rotas legadas.
