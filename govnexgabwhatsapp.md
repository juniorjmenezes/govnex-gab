# Integração do GOVNEX GAB com o Gateway WhatsApp

## Objetivo

O GOVNEX GAB utiliza o Gateway WhatsApp F3 Sistemas para notificações transacionais. O aplicativo não acessa a Meta diretamente e não compartilha banco com o gateway.

- Cliente técnico no gateway: `GABINETE`.
- Nome operacional: `GOVNEXGAB`.
- Conta operacional: número terminado em `0874`, selecionado pelo ID interno do gateway.
- Gateway de produção: `https://whatsapp.f3sistemas.app.br`.
- Callback privado: `POST /api/integrations/whatsapp/callback`.
- Handler: `TRANSACTIONAL_NOTIFICATION`.

O canal `whatsapp_simulado` permanece disponível para desenvolvimento e histórico. O canal real é `whatsapp` e nasce desativado.

O webhook público da Meta permanece no gateway. A única URL alterada para o
GOVNEX GAB é o callback privado do cliente `GABINETE`.

O número terminado em `9273` pertence ao sistema Gabinete legado e ao cliente
`GABINETE_F3`. Ele não compartilha templates, segredos, histórico ou callback
privado com o GOVNEX GAB.

## Controles de ativação

Um envio real só é elegível quando todos os controles abaixo estiverem ativos:

1. `WHATSAPP_DRIVER=gateway`;
2. `WHATSAPP_REAL_ENABLED=true`;
3. gabinete em modo `PILOT` ou `LIVE`;
4. finalidade habilitada no gabinete;
5. template aprovado, sincronizado e ativo;
6. contato declarado com consentimento vigente;
7. em `PILOT`, contato explicitamente marcado como piloto;
8. mensagem ainda não expirada e não suprimida.

Nenhum contato ou consentimento legado é promovido automaticamente. O campo histórico `consentimento_contato` não equivale ao consentimento específico do WhatsApp.

## Consentimento e privacidade

O consentimento v1.1 é único para notificações operacionais da plataforma e não cobre marketing:

> Autorizo o GOVNEX GAB, operado pela F3 Sistemas, a enviar por WhatsApp notificações relacionadas ao meu uso ou atendimento na plataforma, incluindo demandas, agenda, pesquisas e outros avisos operacionais. Posso revogar esta autorização a qualquer momento.

O número é classificado como `DECLARADO`, não como verificado. O aceite registra versão, hash do texto, origem, responsável e data. `SAIR`, `CANCELAR` ou revogação no sistema desativa todo o canal e sincroniza a supressão no gateway.

Telefones e parâmetros são cifrados no banco. Hash e últimos quatro dígitos são mantidos para reconciliação e auditoria. Dados sensíveis locais são purgados após 90 dias.

## Finalidades iniciais

| Finalidade | Público | Disparo |
|---|---|---|
| `DEMANDA_ATRIBUIDA` | Equipe responsável | Imediato |
| `DEMANDA_STATUS_ALTERADO` | Equipe e cidadão vinculado | Imediato |
| `DEMANDA_OBSERVACAO_ADICIONADA` | Equipe envolvida | Imediato, sem o texto da observação |
| `DEMANDAS_PRAZO_RESUMO` | Equipe envolvida | Resumo diário, padrão 08:00 |
| `AGENDA_LEMBRETE_EQUIPE` | Responsável e participantes | Conforme antecedência configurada |
| `AGENDA_LEMBRETE_CIDADAO` | Cidadão vinculado | Conforme antecedência configurada |
| `AGENDA_ALTERADA` | Participantes aplicáveis | Imediato |
| `AGENDA_CANCELADA` | Participantes aplicáveis | Imediato |
| `PESQUISA_ELEITORAL_PUBLICADA` | Equipe autorizada | Desativado até consentimento de marketing próprio |

As notificações internas do Laravel são independentes. Falha no WhatsApp nunca impede uma operação do GOVNEX GAB.

## Contrato com o gateway

As requisições usam os cabeçalhos:

- `X-WhatsApp-Client`;
- `X-WhatsApp-Timestamp`;
- `X-WhatsApp-Nonce`;
- `X-WhatsApp-Signature`.

A assinatura é HMAC-SHA256 sobre:

```text
METHOD
PATH_COM_QUERY
TIMESTAMP
NONCE
SHA256(CORPO)
```

O envio usa `POST /api/internal/v1/messages/templates` e um `client_request_id` UUID estável. Resultado ambíguo é reconciliado por `GET /api/internal/v1/messages/{client_request_id}` antes da repetição idempotente.

Templates são administrados pelo ADMIN do GOVNEX GAB através dos endpoints privados do gateway. Categoria, idioma, finalidade, parâmetros e host dos botões são contratos fixos. Somente o texto de um rascunho pode ser alterado sem mudar os marcadores obrigatórios.

`WHATSAPP_GATEWAY_ACCOUNT_ID` fixa a conta de destino para listagem, criação,
submissão e sincronização dos templates. A ausência desse valor bloqueia a
recriação de versões. A rotina operacional padrão recria as oito finalidades
`UTILITY` e exclui `PESQUISA_ELEITORAL_PUBLICADA`, que foi classificada como
marketing pela Meta e exige consentimento separado.

## Callbacks

O callback é stateless, assinado e não usa sessão ou CSRF. Ele aceita:

- `MESSAGE_STATUS`: atualiza estados monotônicos;
- `CONTACT_OPTOUT`: revoga o contato pelo hash pseudonimizado;
- `INBOUND_MESSAGE`: registra apenas metadados sanitizados, sem automação conversacional.

Cada `event_id` é processado uma vez. Timestamp fora da janela, nonce repetido, assinatura inválida ou cliente diferente de `GABINETE` são recusados.

## Filas, expiração e retries

- Fila exclusiva: `whatsapp`.
- Timeout de chamada: 20 segundos.
- Retry: rede, HTTP 429 e HTTP 5xx.
- HTTP 4xx funcional: falha definitiva e sanitizada.
- Timeout ambíguo: estado `RECONCILING`, consulta por UUID e repetição somente com o mesmo payload.
- Mensagens vencidas: `EXPIRED`, sem envio.

## Operação

### Desenvolvimento

```dotenv
WHATSAPP_DRIVER=fake
WHATSAPP_REAL_ENABLED=false
```

Testes devem usar `Http::fake()` e nunca alcançar o gateway ou a Meta.

### Produção

Os segredos ficam somente em `/etc/f3/secrets/gabnex/application.env`. O handoff preserva o segredo estável de pseudonimização, rotaciona os segredos HMAC e mantém os anteriores válidos por 24 horas.

A ativação segue a ordem:

1. backup dos bancos GOVNEX GAB e gateway;
2. migrations do GOVNEX GAB;
3. callback validado com envio real desligado;
4. conta `0874` fixada por `WHATSAPP_GATEWAY_ACCOUNT_ID`;
5. templates operacionais criados, aprovados e sincronizados;
6. ativação manual somente das finalidades homologadas;
7. dry-run sem alteração operacional;
8. modo `PILOT` com um usuário e um cidadão;
9. ativação gradual das finalidades;
10. modo `LIVE` somente após homologação.

Antes do piloto, execute `php artisan govnexgab:whatsapp-audit --strict`. O modo
estrito exige exatamente um integrante da equipe e um cidadão marcados como
piloto, além de todos os templates ativos. O handoff do gateway usa simulação
por padrão e só grava com `whatsapp:handoff-gabnex --confirm`; os segredos não
são exibidos no terminal.

### Ensaio ampliado em LIVE

O comando `govnexgab:whatsapp-live-test` valida os oito templates operacionais com
dois contatos de equipe e três cidadãos. A execução padrão é uma simulação e
mostra somente nomes, IDs internos e os quatro últimos dígitos. O envio real
exige gabinete em `LIVE`, outbox sem pendências externas ao ensaio, um UUID,
`--confirm-live` e `--expected-messages=40`.

Os lotes devem ser liberados separadamente: `demands`, `digest`,
`agenda-reminders`, `agenda-changed` e `agenda-cancelled`. O mesmo `--run-id`
permite retomada idempotente. Ao final, `--stage=report --wait-seconds=900`
exige exatamente 40 notificações e estado mínimo `SENT`. Falha definitiva
interrompe novos lotes e resultado ambíguo nunca deve ser reenviado manualmente.
O manifesto privado fica em
`storage/app/private/whatsapp-live-tests/<run-id>.json`, sem telefone completo
ou parâmetros descriptografados.

Em 5 de agosto de 2026, o fluxo foi homologado no Gabinete Modelo de Fortaleza
com dois contatos de equipe, três cidadãos e 40 notificações únicas. As oito
finalidades alcançaram ao menos `DELIVERED`, 21 mensagens chegaram a `READ` na
conferência final, não houve falha definitiva e a repetição do mesmo ensaio não
criou novas notificações. Os registros funcionais foram preservados para
auditoria, e o manifesto detalhado permanece somente no storage privado de
produção.

### Rollback

Desligar `WHATSAPP_REAL_ENABLED`, colocar gabinetes em `OFF` e parar apenas o worker `whatsapp`. Preservar outbox e callbacks. Se necessário, restaurar callback e segredos anteriores durante a janela de compatibilidade; banco só é restaurado mediante confirmação explícita.
