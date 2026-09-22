import * as L from 'leaflet';

/**
 * `leaflet.heat` não é um módulo ESM/CJS de verdade: é um script legado que
 * referencia `L` como variável global (`L.heatLayer = ...`) e espera que ela
 * já exista em `window`, no estilo de quando se carregava `<script>` do
 * Leaflet antes do plugin na página. Empacotado pelo Vite, essa suposição
 * falha — a build de produção não garante que `window.L` aponte para a
 * mesma instância que `import * as L from 'leaflet'` usa no componente, e
 * `L.heatLayer` fica `undefined` (só em produção; no dev server o
 * comportamento de bundling difere e mascara o problema).
 *
 * Este módulo precisa ser importado ANTES de `leaflet.heat` (a ordem de
 * `import` no arquivo determina a ordem de execução) para garantir que a
 * variável global exista com a referência certa quando o plugin rodar.
 */
if (typeof window !== 'undefined') {
    (window as typeof window & { L: typeof L }).L = L;
}

export {};
