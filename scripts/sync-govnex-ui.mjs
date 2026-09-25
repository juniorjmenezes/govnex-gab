#!/usr/bin/env node
/**
 * Espelha a fonte canônica do `@govnex/ui` dentro do GAB.
 *
 * A fonte única do pacote é o repositório `govnex-ui` (por padrão em
 * `../govnex-ui`, ao lado deste repositório). O GAB consome uma cópia em
 * `packages/govnex-ui` (`"@govnex/ui": "file:packages/govnex-ui"`), resolvida
 * direto de `src/` pelo Vite — por isso a cópia continua versionada aqui: o
 * build do GAB não depende de o outro repositório existir na máquina.
 *
 * Regra: edite SÓ em `govnex-ui` e rode `npm run sync:ui` aqui. Nada dentro de
 * `packages/govnex-ui` deve ser editado à mão — este script sobrescreve.
 *
 * O que é copiado:
 * - `src/` inteiro (arquivos que não existem mais na origem são removidos);
 * - `tsconfig.json` e `tsup.config.ts`;
 * - `dependencies`/`peerDependencies`/`devDependencies` do `package.json`.
 *
 * O que NÃO é copiado: `exports`/`files`/`scripts` do `package.json` (no GAB
 * o pacote aponta para `./src`, fora dele para `./dist`) e o README, que aqui
 * é gerado com o aviso de espelho à frente do README canônico.
 *
 * Uso: `npm run sync:ui` ou `node scripts/sync-govnex-ui.mjs [caminho-da-origem]`
 * (a origem também pode vir de `GOVNEX_UI_PATH`). `--check` só compara e sai
 * com código 1 se o espelho estiver defasado, sem escrever nada.
 */
import { Buffer } from 'node:buffer';
import { createHash } from 'node:crypto';
import {
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const checkOnly = args.includes('--check');
const sourceArg = args.find((arg) => !arg.startsWith('--'));
const source = resolve(
    root,
    sourceArg ?? process.env.GOVNEX_UI_PATH ?? '../govnex-ui',
);
const target = join(root, 'packages/govnex-ui');

const mirrorNotice = `<!--
  ESPELHO GERADO — NÃO EDITAR.
  Este diretório é copiado de \`govnex-ui\` (fonte canônica) por
  \`npm run sync:ui\` (scripts/sync-govnex-ui.mjs). Edições feitas aqui são
  sobrescritas na próxima sincronização: altere o repositório govnex-ui e
  sincronize.
-->

> **Espelho gerado — não editar.** A fonte canônica é o repositório
> \`govnex-ui\`; atualize com \`npm run sync:ui\` na raiz do GAB.

`;

if (!existsSync(join(source, 'src/index.ts'))) {
    console.error(
        `[sync:ui] Origem não encontrada: ${source}\n` +
            'Clone o govnex-ui ao lado do GAB ou informe o caminho ' +
            '(argumento ou GOVNEX_UI_PATH).',
    );
    process.exit(1);
}

const changes = [];

function hash(buffer) {
    return createHash('sha1').update(buffer).digest('hex');
}

function listFiles(dir) {
    if (!existsSync(dir)) {
        return [];
    }

    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

function writeIfChanged(path, content) {
    const next = Buffer.isBuffer(content) ? content : Buffer.from(content);

    if (existsSync(path) && hash(readFileSync(path)) === hash(next)) {
        return;
    }

    changes.push(`atualizado ${relative(root, path)}`);

    if (!checkOnly) {
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, next);
    }
}

// 1. src/ espelhado, com remoção do que sumiu da origem.
const sourceSrc = join(source, 'src');
const targetSrc = join(target, 'src');
const sourceFiles = new Set(
    listFiles(sourceSrc).map((path) => relative(sourceSrc, path)),
);

for (const file of sourceFiles) {
    writeIfChanged(join(targetSrc, file), readFileSync(join(sourceSrc, file)));
}

for (const path of listFiles(targetSrc)) {
    if (!sourceFiles.has(relative(targetSrc, path))) {
        changes.push(`removido ${relative(root, path)}`);

        if (!checkOnly) {
            rmSync(path);
        }
    }
}

// 2. Configurações de compilação.
for (const file of ['tsconfig.json', 'tsup.config.ts']) {
    if (existsSync(join(source, file))) {
        writeIfChanged(join(target, file), readFileSync(join(source, file)));
    }
}

// 3. Dependências do package.json (mantendo exports/scripts locais).
const sourcePackage = JSON.parse(
    readFileSync(join(source, 'package.json'), 'utf8'),
);
const targetPackagePath = join(target, 'package.json');
const targetPackage = JSON.parse(readFileSync(targetPackagePath, 'utf8'));
let dependenciesChanged = false;

for (const field of ['dependencies', 'peerDependencies', 'devDependencies']) {
    if (
        JSON.stringify(sourcePackage[field] ?? {}) !==
        JSON.stringify(targetPackage[field] ?? {})
    ) {
        targetPackage[field] = sourcePackage[field];
        dependenciesChanged = true;
    }
}

targetPackage.version = sourcePackage.version;
writeIfChanged(
    targetPackagePath,
    `${JSON.stringify(targetPackage, null, 4)}\n`,
);

// 4. README com o aviso de espelho.
writeIfChanged(
    join(target, 'README.md'),
    mirrorNotice + readFileSync(join(source, 'README.md'), 'utf8'),
);

if (changes.length === 0) {
    console.log('[sync:ui] Espelho já está em dia com', source);
    process.exit(0);
}

console.log(changes.map((line) => `[sync:ui] ${line}`).join('\n'));

if (checkOnly) {
    console.error('[sync:ui] Espelho defasado — rode `npm run sync:ui`.');
    process.exit(1);
}

console.log(`[sync:ui] ${changes.length} alteração(ões) copiadas de ${source}`);

if (dependenciesChanged) {
    console.warn(
        '[sync:ui] As dependências do pacote mudaram: rode `npm install` no GAB.',
    );
}
