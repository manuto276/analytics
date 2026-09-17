import { build } from 'esbuild';

const banner = '/*! analytics | AGPL-3.0-or-later | source: https://github.com/manuto276/analytics */';

await build({
  entryPoints: ['src/index.ts'],
  outfile: 'dist/tracker.js',
  bundle: true,
  format: 'iife',
  target: 'es2019',
  minify: true,
  legalComments: 'none',
  banner: { js: banner },
  logLevel: 'info',
});
