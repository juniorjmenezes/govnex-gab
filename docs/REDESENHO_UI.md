# Redesenho da interface GOVNEX ("SaaS moderno e suave")

Estado e pendências do redesenho visual que começou em 25/09/2026. O objetivo é a interface parecer produto pago:
menos "painel interno". A direção foi escolhida pelo dono do projeto: **SaaS moderno e suave** (referências Linear, Stripe, Vercel),
com piloto no painel Visão geral do GAB, **implementado na biblioteca compartilhada** para valer depois para Hub e GOVNEX API.

Documentos relacionados: [AMBIENTE_DEV_HUB.md](AMBIENTE_DEV_HUB.md) (montagem do ambiente) e o `README.md` do repositório `govnex-ui`.

## Onde se edita (fonte única)

- **`C:\dev\htdocs\govnex-ui` (`github.com/juniorjmenezes/govnex-ui`) é a fonte canônica** dos tokens e componentes. Edite só lá.
- O GAB recebe um **espelho gerado** em `packages/govnex-ui` (não editar). Depois de mudar o pacote: `npm run sync:ui` no GAB
  (`sync:ui -- --check` só compara). O script é `scripts/sync-govnex-ui.mjs`.
- Hub e GOVNEX API consomem o pacote por `github:juniorjmenezes/govnex-ui`, com **commit fixo no lockfile, defasado** em relação ao HEAD. Continuam no visual antigo até `npm update @govnex/ui` + rebuild.
- O GAB serve `public/build`: depois de mudar o front, `npm run build` para ver em `http://127.0.0.1:8000`.

## O que já foi feito (commitado)

**Sistema visual em `govnex-ui`**
- Raio `--radius: 0.625rem` com escala real (6, 8, 10, 14, 16 px): controles `rounded-md`, superfícies `rounded-xl`; `Badge`/`Switch` seguem `rounded-full`; altura `h-10` dos controles mantida.
- Tokens de sombra `--shadow-xs/sm/md/lg` (no escuro viram borda e brilho); `Surface`/`Card` com `shadow-xs` e prop `interactive`.
- Fundo levemente cinza, cartões brancos, bordas suaves; tokens semânticos `--success/--warning/--info/--destructive` (+ `-foreground`, `-soft`); `Badge` com variantes `success|warning|info|danger`.
- Sem caixa alta em Button, CardTitle, Breadcrumb, TableHead, SidebarGroupLabel, TableGroupRow, itens de menu e rótulos. Escala tipográfica nova (título de página `text-2xl`).
- Padrões novos: `PageHeader` empilhado, `StatCard` v2 (`trend`, `sparkline`, ícone em chip), `StatCardSkeleton`, `Sparkline`, `SectionCard`, `EmptyState` compacto.
- `npm run contrast:check` (`scripts/check-contrast.mjs`): todos os pares do tema em ≥ 4,5:1 nos dois temas.

**Shell do GAB**
- Sidebar clara (marca + nome do gabinete), item ativo com fundo suave na cor do gabinete, rodapé do usuário; colapsada e celular (Sheet) preservados.
- Cabeçalho sem o título duplicado (breadcrumb só em páginas aninhadas), busca com Ctrl/⌘+K.
- Faixa de abas (`AppMenubar`) **removida**: Categorias, Bairros, Equipe (condicional), Relatórios e Configurações viraram o grupo "Gestão do gabinete" na sidebar (`useManagementNavItems`).
- `--app-shell-height` passou a valor único (`3.5rem + 1px`). O tema por gabinete (`cor_principal`, `OfficeTheme`) foi preservado; o texto sobre a cor agora é escolhido pelo maior contraste.

**Painel Visão geral (em andamento — ver abaixo)**
- Backend: `DashboardMetricsService` devolve, por indicador, o valor do período anterior e uma série curta para a sparkline; tipos em `resources/js/types/dashboard.ts`; testes em `tests/Feature/DashboardTest.php`.
- Front: `pages/dashboard.tsx` reescrito com `PageHeader`, `StatCard` v2 com tendência e sparkline, e componentes em `resources/js/components/dashboard/` (`evolution-chart`, `dashboard-lists`, `status-overview`, `dashboard-format`).
- Verificado no último estado: `types:check`, `contrast:check`, `sync:ui --check`, `tsc --noEmit`, `npm run build`, 596 testes passando (2 ignorados) e Pint limpos.

## O que falta para concluir

O trabalho foi interrompido durante a etapa 4; o que segue está na ordem sugerida.

1. **Aprovação visual do piloto (bloqueia o resto).** Abrir `http://127.0.0.1:8000/dashboard` em claro, escuro, sidebar colapsada e largura de celular, com root e por SSO como `vereador@gabinetefacil.test` (senha do Hub `hub-dev-12345`). O painel foi reescrito, mas o agente que o fez **não chegou a conferir as capturas finais**: revisar alinhamentos, pesos, espaçamentos, legibilidade dos gráficos e se há cartões meio vazios.
2. **Modo escuro possivelmente quebrado.** A captura `root-dark.png` do agente anterior saiu **toda preta**; não se sabe se foi problema de captura ou erro real (tokens `.dark`, `color-scheme`, fundo). Conferir pelo botão de tema; se for real, corrigir em `govnex-ui/src/styles.css`.
3. **Conferir o grupo "Gestão do gabinete"** na sidebar: só aparece para usuário não-root (o root não o vê por regra), então nunca foi visto em captura. Entrar por SSO como não-root.
4. **Ajustes já conhecidos no painel/tema**
   - Cores de gráfico no claro: `--chart-2`, `--chart-4` e `--chart-5` ficam abaixo de 3:1 sobre branco — precisam de legenda ou rótulo.
   - Botões destrutivos e Badge `destructive` no escuro (vermelho-claro com texto escuro): conferir o visual.
   - O root dentro de um gabinete vê o grupo "Plataforma" duplicado na sidebar (já acontecia antes).
   - Fora do root, os mapas ganham 2,5 rem de altura com a mudança de `--app-shell-height`: conferir `electoral-map.tsx` e `prospecting-map.tsx`.
5. **Varredura das demais telas do GAB (etapa 5, só depois de aprovar o piloto).**
   - Caixa alta escrita direto em **15 arquivos** de tela (`grep -rn uppercase resources/js`; ex.: cabeçalho "GABINETE" da tabela no painel da plataforma) — remover onde contradiz o novo sistema.
   - ~32 usos de `rounded-lg/xl/2xl` nas telas que ficaram mais arredondados: conferir coerência.
   - `PageHeader` agora é `text-2xl` em 44 páginas: navegar por demandas, cidadãos, agenda, relatórios, configurações e ver se algo quebrou visualmente.
6. **Documentação de regras (etapa 6).** Estão desatualizadas:
   - `AGENTS.md` do GAB, l.10: "raio único 0.2rem" → escala de 0,625 rem (controles 8 px, superfícies `rounded-xl`, exceção do calendário mudou); l.8: "tokens refletem `stone`" vale só em parte (fundo, tokens semânticos, sombra, gráfico); regra de cards (título `text-base`); regra de modais (`shadow-lg`, `ring-foreground/8`, `rounded-xl`); regra de campos/botões (`rounded-md` = 8 px, padding dos botões mudou); a ponte de ícones ganhou `ArrowUpIcon`, `ArrowRightUpIcon` e `ArrowRightDownIcon`.
   - `docs/LINGUAGEM_VISUAL.md` do Hub: §2 (raio), §4 (título de página `text-xs uppercase`) e a fonte citada (Geist; o código usa Inter Tight). Plano: mover para o repositório `govnex-ui` como fonte canônica e apontar Hub e GAB para ele.
   - Regras que **não existem ainda** e precisam ser escritas: tokens semânticos e variantes de Badge, sombras, `StatCard` v2, `SectionCard`, `EmptyState` compacto, Cmd+K e o grupo "Gestão do gabinete".
7. **Hub e GOVNEX API adotam o pacote novo.** `npm update @govnex/ui` (avança o commit do lockfile), conferir `@source` no `app.css`, `npm run build`, e repetir a varredura de caixa alta/raio em cada um. O Hub também tem `resources/js/components/icons.tsx` como ponte de ícones (adicionar os novos ícones se usados).
8. **Limpezas.** `eslint` aponta erros antigos (fora das linhas alteradas) em `calendar.tsx`, `sidebar.tsx`, `color-picker.tsx`, `date-picker.tsx`, `date-time-field-pair.tsx` e `field-label.tsx` do pacote; `npm run lint:check`/`format:check` do GAB falham por arquivos alheios (pasta de worktree em `.claude/`, `packages/govnex-ui`, três arquivos da Base de Conhecimento).
9. **Decisão pendente do dono do projeto.** Manter a altura `h-10` dos controles (40 px) ou passar para 36 px — mantida por ora para não mexer no layout dos formulários.

## Fora de escopo por ora

GRI e GPC (não usam a `govnex-ui`); troca de biblioteca de componentes, Base UI ou Next.js; ícones (seguem `@solar-icons/react` via `components/icons`).

## Referências pesquisadas (templates shadcn)

Só como inspiração de padrões, não como base: [satnaing/shadcn-admin](https://github.com/satnaing/shadcn-admin) (layout, sidebar, Cmd+K),
[shadcnstore/shadcn-dashboard-landing-template](https://github.com/shadcnstore/shadcn-dashboard-landing-template) (React 19 + Tailwind v4: dashboards, calendário, configurações, telas de erro),
[blocos oficiais do shadcn](https://ui.shadcn.com/blocks) (`dashboard-01`, `sidebar-*`, `login-*`), [tweakcn](https://tweakcn.com/) (editor de tema),
[tablecn](https://github.com/sadmann7/tablecn) (tabela avançada). Todos são para SPA (Vite ou Next.js): cada tela exige porte para Inertia.
