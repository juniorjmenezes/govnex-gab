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
- `styles.css`: tokens claro/escuro, tipografia e escala de raios GOVNEX.

Componentes de produto, chamadas HTTP, rotas, permissoes e estado do Inertia
nao pertencem a este pacote.

## Uso no workspace

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

Enquanto o pacote estiver em `0.x`, novas extracoes devem ser validadas no
GOVNEX GAB e em pelo menos outro produto GOVNEX antes de estabilizar a API.
