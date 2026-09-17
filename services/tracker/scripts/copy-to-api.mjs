import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const target = resolve(root, '../api/resources/tracker/tracker.js');
mkdirSync(dirname(target), { recursive: true });
copyFileSync(resolve(root, 'dist/tracker.js'), target);
console.log(`copied dist/tracker.js -> ${target}`);
