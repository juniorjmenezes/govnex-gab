<!--
  ESPELHO GERADO — NÃO EDITAR.
  Este diretório é copiado de `govnex-ui` (fonte canônica) por
  `npm run sync:ui` (scripts/sync-govnex-ui.mjs). Edições feitas aqui são
  sobrescritas na próxima sincronização: altere o repositório govnex-ui e
  sincronize.
-->

> **Espelho gerado — não editar.** A fonte canônica é o repositório
> `govnex-ui`; atualize com `npm run sync:ui` na raiz do GAB.

# @govnex/ui

Nucleo visual compartilhado dos produtos GOVNEX.

O pacote contem somente componentes e tokens independentes de produto,
roteamento, autorizacao e transporte. Integracoes com Laravel/Inertia devem
ficar nos aplicativos ou em adaptadores separados.

## Camadas atuais

- `components`: primitivos visuais, incluindo campos, dialogs, alert dialogs,
  sheets, drawers, superficies, cards, tabelas e feedback de progresso.
- `patterns`: composicoes GOVNEX para cabecalho de pagina, estado vazio,
  indicadores e acoes de tabela.
- `icons`: ponte oficial para Solar Icons; consumidores nao devem importar a
  biblioteca de icones diretamente.
- `styles.css`: tokens claro/escuro, tipografia, escala de raios
  (`--radius` 0,625rem: sm 6px, md 8px, lg 10px, xl 14px, 2xl 16px),
  sombras (`--shadow-xs/sm/md/lg`; no escuro, borda e brilho) e tokens
  semanticos `--success/--warning/--info/--destructive`, cada um com
  `-foreground` e `-soft`. `--chart-1` segue o destaque (`--primary`);
  `--chart-2..5` sao matizes categoricos fixos, distintos das cores de estado.

### Contraste

`npm run contrast:check` calcula o contraste WCAG dos pares de tokens nos
dois temas (texto sobre superficies, semanticos solidos e suaves, item ativo
da sidebar com cores de gabinete de exemplo) e falha abaixo de 4,5:1. Rode
depois de qualquer mudanca de cor em `styles.css`.

### Padroes de pagina

- `PageHeader`: trilha opcional (`breadcrumb`), titulo `text-2xl`, descricao
  abaixo, acoes a direita.
- `SectionCard` / `Surface` + `SurfaceHeader`: cartao de secao com titulo
  `text-base`; `interactive` eleva o cartao no hover.
- `StatCard`: rotulo, valor, `trend` (variacao % vs periodo anterior, cor
  semantica; `positiveIsGood={false}` inverte) e `sparkline` (serie curta);
  `StatCardSkeleton` para carregamento.
- `EmptyState` (`size="compact"` dentro de cartoes) e `Sparkline`.

Componentes de produto, chamadas HTTP, rotas, permissoes e estado do Inertia
nao pertencem a este pacote.

## Fonte unica (onde editar)

Este repositorio e a **fonte canonica** do `@govnex/ui`. Toda mudanca de
token, componente ou padrao e feita **somente aqui**.

- **GOVNEX GAB** consome uma copia espelhada em `packages/govnex-ui`
  (`"@govnex/ui": "file:packages/govnex-ui"`, resolvida direto de `src/`).
  Essa copia e gerada por `npm run sync:ui` (script
  `scripts/sync-govnex-ui.mjs` do GAB, que le `../govnex-ui`) e **nao deve
  ser editada a mao** — a proxima sincronizacao sobrescreve. Depois de
  alterar este repositorio, rode `npm run sync:ui` no GAB (`--check` apenas
  compara e falha se o espelho estiver defasado).
- **Hub e API** instalam via dependencia Git (abaixo) e so recebem mudancas
  quando atualizam a referencia no lockfile (`npm update @govnex/ui`).

## Instalacao

Repositorio privado, instalado via dependencia Git (sem registry por enquanto):

```json
"dependencies": {
    "@govnex/ui": "git+https://github.com/juniorjmenezes/govnex-ui.git"
}
```

O `npm install` roda o script `prepare` automaticamente apos clonar,
compilando `dist/` (nao versionado) antes de disponibilizar o pacote.

## Uso

```tsx
import { Button, Input } from '@govnex/ui';
```

Icones podem ser importados pela entrada dedicada:

```tsx
import { CalendarIcon } from '@govnex/ui/icons';
```

No CSS principal do consumidor, importe o tema depois do Tailwind e do
shadcn:

```css
@import 'tailwindcss';
@import 'shadcn/tailwind.css';
@import '@govnex/ui/styles.css';
```

### `@source` obrigatorio (Tailwind v4)

A deteccao automatica de conteudo do Tailwind v4 ignora o que estiver no
`.gitignore` do consumidor — e `node_modules` normalmente esta la. Sem o
`@source` abaixo, as classes usadas dentro de `dist/index.js` (ex.:
`tracking-widest`, `group/button`) nunca sao geradas, e a interface fica
sem estilo nenhum: bordas, cores e radius somem, sobra so texto puro.

```css
@source '../../node_modules/@govnex/ui/dist';
```

(ajuste o caminho relativo conforme a posicao do `app.css` no projeto).
Isso so se aplica a quem instala via `node_modules` — o GOVNEX GAB, que
consome `packages/govnex-ui/src` direto do proprio repositorio (nao
ignorado pelo git), nao precisa disso.

Enquanto o pacote estiver em `0.x`, novas extracoes devem ser validadas no
GOVNEX GAB e em pelo menos outro produto GOVNEX antes de estabilizar a API.
