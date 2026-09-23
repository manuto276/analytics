// dist/esm (ES modules) and dist/cjs (CommonJS), each with its .d.ts, compiled by tsc.
// dist/cjs/package.json marks that directory as CommonJS for Node and for TypeScript.
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const tsc = join(root, 'node_modules/.bin/tsc');

rmSync(join(root, 'dist'), { recursive: true, force: true });
execFileSync(tsc, ['-p', 'tsconfig.build.json'], { cwd: root, stdio: 'inherit' });
execFileSync(tsc, ['-p', 'tsconfig.build.cjs.json'], { cwd: root, stdio: 'inherit' });
mkdirSync(join(root, 'dist/cjs'), { recursive: true });
writeFileSync(join(root, 'dist/cjs/package.json'), '{ "type": "commonjs" }\n');
console.log('built dist/esm and dist/cjs');
