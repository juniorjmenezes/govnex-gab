import { defineConfig } from 'tsup';

/**
 * Gera `dist/` compilado (JS + `.d.ts`) para consumo fora do workspace do
 * GAB — outro repositório instalando `@govnex/ui` como dependência de
 * verdade não consegue transpilar TS/JSX direto de `node_modules` como o
 * Vite do GAB faz hoje (ele trata o pacote como parte do próprio workspace,
 * via symlink). Isso é puramente aditivo: os `exports` do `package.json`
 * continuam apontando para `./src`, então o GAB não muda de comportamento.
 */
export default defineConfig({
    entry: {
        index: 'src/index.ts',
        icons: 'src/icons.ts',
    },
    format: ['esm'],
    dts: true,
    sourcemap: true,
    clean: true,
    splitting: true,
    treeshake: true,
    // Tudo que consta em `dependencies`/`peerDependencies` fica de fora do
    // bundle — quem instalar o pacote também instala essas dependências
    // normalmente, evitando duas cópias de React/Recharts/etc.
    external: [
        'react',
        'react-dom',
        'react/jsx-runtime',
        'tailwindcss',
        '@base-ui/react',
        '@fontsource-variable/fira-code',
        '@fontsource-variable/inter-tight',
        '@solar-icons/react',
        'class-variance-authority',
        'clsx',
        'date-fns',
        'input-otp',
        'radix-ui',
        'react-colorful',
        'react-day-picker',
        'recharts',
        'tailwind-merge',
    ],
    esbuildOptions(options) {
        // As duas animações do `DestructiveAlertDialog` são .gif pequenos
        // (≈50 KB juntos) importados como módulo — copiamos para
        // `dist/assets` em vez de embutir em base64, mantendo o JS enxuto.
        options.loader = {
            ...options.loader,
            '.gif': 'file',
        };
        options.assetNames = 'assets/[name]-[hash]';
    },
});
