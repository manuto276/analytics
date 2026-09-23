import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const targetDir = resolve(root, '../api/resources/tracker');
mkdirSync(targetDir, { recursive: true });
for (const file of ['tracker.js', 'banner.js']) {
  copyFileSync(resolve(root, 'dist', file), resolve(targetDir, file));
  console.log(`copied dist/${file} -> ${resolve(targetDir, file)}`);
}
