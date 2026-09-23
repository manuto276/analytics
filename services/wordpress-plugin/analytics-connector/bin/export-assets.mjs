/**
 * The icon and the banners, from their SVG sources in design/ to the PNGs WordPress and the
 * README show. Run after changing a source (Playwright comes with `npm install`):
 *
 *   node bin/export-assets.mjs
 */
import { chromium } from 'playwright';
import { copyFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const jobs = [
	[ 'icon.svg', '.github-assets/icon-512.png', 512, 512, 1 ],
	[ 'icon.svg', 'assets/icon-256x256.png', 256, 256, 0.5 ],
	[ 'icon.svg', 'assets/icon-128x128.png', 128, 128, 0.25 ],
	[ 'banner-light.svg', '.github-assets/banner-light.png', 2560, 640, 1 ],
	[ 'banner-dark.svg', '.github-assets/banner-dark.png', 2560, 640, 1 ],
];

const browser = await chromium.launch();
try {
	for ( const [ source, target, width, height, scale ] of jobs ) {
		const page = await browser.newPage( { viewport: { width, height } } );
		const svg = readFileSync( path.join( root, 'design', source ), 'utf8' );
		await page.setContent( `<!doctype html><style>html,body{margin:0;background:transparent}svg{display:block;width:${ width }px;height:${ height }px}</style>${ svg }` );
		await page.screenshot( { path: path.join( root, target ), omitBackground: true } );
		console.log( `✓ ${ target } (${ scale }×)` );
		await page.close();
	}
	// The admin header shows the SVG itself.
	copyFileSync( path.join( root, 'design/icon.svg' ), path.join( root, 'assets/icon.svg' ) );
	console.log( '✓ assets/icon.svg' );
} finally {
	await browser.close();
}
