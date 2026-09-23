import { build } from 'esbuild';

const banner = '/*! analytics | AGPL-3.0-or-later | source: https://github.com/manuto276/analytics */';
const common = {
  bundle: true,
  format: 'iife',
  target: 'es2019',
  minify: true,
  legalComments: 'none',
  banner: { js: banner },
  logLevel: 'info',
};

// Core: everything but the banner UI. Served alone to sites without the cookie level.
await build({ ...common, entryPoints: ['src/index.ts'], outfile: 'dist/tracker.js' });
// Banner UI: the server puts it before the core only when the site has the cookie level on.
await build({ ...common, entryPoints: ['src/banner/index.ts'], outfile: 'dist/banner.js' });
