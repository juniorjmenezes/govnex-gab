# Instruções para agentes

## Projeto

GOVNEX GAB é um SaaS multi-tenant para gestão de demandas, agenda e operação de gabinetes parlamentares.

- Backend: PHP 8.4.1, Laravel 13 e MariaDB/MySQL.
- Frontend: React 19, Inertia 3, TypeScript estrito, Vite, Tailwind CSS e shadcn/ui.
- O preset shadcn `b7D49K45A` (`radix-sera`, tema `orange`) é a base visual oficial. A cor neutra foi trocada manualmente de `mist` (padrão do preset) para `stone` — os tokens em `resources/css/app.css` refletem `stone`, não o preset original; `primary`/`sidebar-primary` continuam laranja. A biblioteca de primitivos é Radix — o `-b base` (Base UI) foi tentado e revertido por quebrar `asChild` e outras APIs em dezenas de componentes de negócio; não trocar sem migrar esses consumidores antes. **Exceção deliberada**: `combobox.tsx`, `app-select.tsx` e `autocomplete-input.tsx` continuam sobre `@base-ui/react`, porque Radix não tem primitivo nativo de combobox/autocomplete — reverter exigiria reconstruir tudo sobre `Popover`+`Command` (cmdk), o mesmo tipo de mudança em massa que já quebrou componentes de negócio antes. Não "consertar" isso sem medir esse custo primeiro.
- Toda a escala de raios usa `0.2rem` (`--radius` em `resources/css/app.css`), como única exceção `Badge` e `Switch` usam `rounded-full` (pill). `ButtonGroup` e o calendário mantêm suas próprias reduções de raio pontuais (`rounded-*-none` / `--cell-radius`) para unir elementos adjacentes — isso é estrutural, não faz parte dessa escala.
- Produção usa a `f3-vps`; a infraestrutura fica em `C:\xampp82\htdocs\f3-infra`.

## Comandos essenciais

- Dependências PHP: `composer install`.
- Dependências frontend: `npm ci`.
- Validação completa: `composer ci:check`.
- Build de produção: `npm run build`.
- Consulte `README.md` para instalação e usuários demonstrativos.
- Consulte `docs/ARCHITECTURE.md` e `govnexgabmodulos.md` para limites e módulos por gabinete.
- Consulte `HOMOLOGATION.md` para o roteiro funcional e visual.

## Regras permanentes

- Preserve Laravel, React, Inertia, TypeScript, Tailwind e o preset shadcn existente.
- Não adicione Bootstrap, jQuery ou outro design system.
- Reutilize `components/ui`; mantenha componentes de negócio fora desse diretório.
- Para dividir assuntos dentro de uma tabela, use `TableGroupRow` (`components/common/table-group-row.tsx`) — uma linha de largura inteira com o título e, opcionalmente, um subtítulo à frente separado por `·`. Não quebre em várias tabelas com cabeçalho próprio: as colunas deixam de se alinhar entre os blocos.
- Todo card (`Surface` ou `Card`) com título usa `SurfaceHeader` + `SurfaceTitle` e, se houver contexto, `SurfaceDescription` (`components/ui/surface.tsx`): título e descrição na mesma linha separados por `·`, ações opcionais à direita pela prop `actions`. A descrição é só contexto curto (contagem, território, período) e nunca quebra a linha; explicações e instruções vão para a prop `help`, que vira um botão de ajuda à direita, depois das ações. Não monte o cabeçalho à mão nem acrescente ícones ao título; em `Card`, use `className="gap-0 py-0"` e dê o padding ao corpo.
- Campos de formulário seguem o padrão do cadastro de demandas — `h-10 rounded-md border border-input bg-muted px-3` — e não o traço inferior sem fundo do preset. Botões avulsos têm a mesma altura (`default`, `sm`, `icon` e `icon-sm` medem `h-10`); `xs`/`icon-xs` ficam para botões dentro de campos. Ao trazer um componente novo pelo CLI do shadcn, alinhe o controle a `input.tsx`/`input-group.tsx` antes de usá-lo, e confira se o CLI não sobrescreveu `button.tsx`, `label.tsx` ou `separator.tsx`.
- Ícones vêm de `components/icons`, nunca da biblioteca direto: é essa ponte que permitiu avaliar Phosphor e Lucide trocando um arquivo só. A escolha vigente é `@solar-icons/react`. Ao trazer um componente do CLI do shadcn, troque o import de `lucide-react` por `@/components/icons` — os nomes que ele usa (`ChevronDownIcon`, `CheckIcon`, `MoreHorizontalIcon`…) já estão mapeados lá.
- Preserve temas claro/escuro, responsividade, teclado, foco e nomes acessíveis.
- Valide autorização no backend e mantenha isolamento completo entre gabinetes.
- Arquivos privados não podem depender apenas de ocultação na interface.
- APIs externas, testes pagos, migrations e deploy exigem autorização explícita.
- Nunca versione ou exponha `.env`, tokens, senhas, uploads ou dados pessoais.
- Preserve alterações preexistentes no worktree e revise o diff antes de sobrescrever arquivos.
- Mudanças de produto candidatas ao upstream devem atualizar `CHANGELOG.md`.
- Deploy somente mediante pedido explícito; nesse caso, consulte `docs/DEPLOY.md`.
- Para falhas conhecidas, consulte `docs/ERROS_RECORRENTES.md`.

## Ciclo de banco enquanto o projeto estiver somente em DEV

- Antes de criar uma migration incremental `add_*` ou `alter_*`, incorpore a coluna, o índice ou a restrição na migration que originalmente cria a tabela.
- Quando uma alteração incremental já existir e ainda não tiver sido publicada, mova seu conteúdo para a migration primária e remova a migration incremental absorvida.
- Depois de consolidar o histórico, valide a reconstrução completa com `migrate:fresh` exclusivamente em banco de teste descartável e execute a suíte automatizada.
- Não aplique essa consolidação a um ambiente publicado ou com dados que precisem ser preservados; a partir da primeira publicação, toda evolução de schema deve usar uma nova migration.

## Princípios de engenharia

- Preserve dados e compatibilidade por padrão. Remova código legado somente após mapear dependências, migrar os dados necessários, validar os consumidores e estabelecer rollback. Alterações de banco exigem migration Laravel controlada e os gates de autorização definidos em `docs/DEPLOY.md`.
- Escolha a implementação mais simples que atenda às necessidades atuais. Nada de abstrações prematuras ou camadas de configuração desnecessárias.
- Construa o sistema em camadas, gradualmente. Primeiro implemente e valide uma versão mínima funcionando de ponta a ponta; depois evolua a partir dela. A publicação continua exigindo solicitação explícita.
- Mantenha os componentes modulares, com separação clara de responsabilidades, respeitando o monólito modular e a estrutura existentes.
- Dê prioridade a bibliotecas maduras e bem mantidas. Não reescreva algo do zero, a menos que exista um motivo realmente muito bom.
- Primeiro, verifique o que as dependências já existentes no projeto conseguem fazer. Só depois considere adicionar novos pacotes ou desenvolver algo do zero. Não presuma de imediato que as bibliotecas atuais não oferecem o recurso necessário.
- Prefira decisões evolutivas e reversíveis que não criem becos sem saída. Soluções temporárias devem ter motivo, escopo e critério explícito de remoção.
- Observe como produtos maduros resolvem o mesmo problema. Use padrões já comprovados em vez de inventar tudo do zero.
