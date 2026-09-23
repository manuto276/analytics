/**
 * The admin screens, end to end, on the WordPress workspace's dev site (:8090) with the
 * service mocked by dev/mu-plugins/analytics-mock.php: set up without a key, a key pasted
 * and never sent back, the Overview with its numbers, chart and lists, the service's
 * refusals in words, what an editor sees, the dashboard widget, Italian, and axe.
 *
 *   npm run test:e2e              (the dev site up: docker compose up -d in wordpress/dev)
 *   SHOTS=/some/dir npm run test:e2e   also saves screenshots
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const WP = process.env.WP_CLI || path.resolve( here, '../../../../../../wordpress/dev/bin/wp' );
const AXE = readFileSync( path.resolve( here, '../../node_modules/axe-core/axe.min.js' ), 'utf8' );
const BASE = process.env.BASE || 'http://localhost:8090';
const SHOTS = process.env.SHOTS || '';
const KEY = 'ak_devMock1_devdevdevdevdevdevdevdevdevdevdevdevdevdevd';
const SECRET = KEY.slice( 12 );

const clean = ( s ) => s.split( '\n' ).filter( ( l ) => l && ! /container/i.test( l ) ).join( '\n' ).trim();
const php = ( code ) => clean( execFileSync( WP, [ 'eval', code ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
let failures = 0;
const check = ( name, ok, detail = '' ) => {
	console.log( `${ ok ? '✓' : '✗' } ${ name }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) {
		failures++;
	}
};
// WordPress's own notices (updates, other plugins) are hidden: the screenshots are of this plugin.
const shot = async ( page, name ) => {
	if ( SHOTS ) {
		await page.addStyleTag( { content: '#wpbody-content > .notice, #wpbody-content > .update-nag, #wpbody-content > .error { display: none !important; }' } );
		await page.screenshot( { path: `${ SHOTS }/analytics-${ name }.png`, fullPage: true } );
	}
};
async function audit( page, name ) {
	await page.addScriptTag( { content: AXE } );
	const result = await page.evaluate( async () => window.axe.run( document.querySelector( '#anc-app' ), { runOnly: [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'best-practice' ] } ) );
	const bad = result.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) );
	check( `a11y: ${ name }`, bad.length === 0, bad.map( ( v ) => `${ v.id }: ${ v.nodes[ 0 ].target.join( ' ' ) }` ).join( ' | ' ) );
}
async function login( browser, user, pass, viewport = { width: 1440, height: 1000 } ) {
	const page = await ( await browser.newContext( { viewport } ) ).newPage();
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	return page;
}
/** Everything the browser receives from WordPress, to look for the key in. */
function recordBodies( page ) {
	const seen = [];
	page.on( 'response', async ( r ) => {
		if ( r.url().startsWith( BASE ) ) {
			seen.push( await r.text().catch( () => '' ) );
		}
	} );
	return seen;
}

// A clean start: connected to the mock, no key, no forced refusal.
php( `update_option('analytics_connector_settings', ['service_url' => 'https://analytics.mock.test', 'public_key' => 'pk_AbCdEfGhIjKlMnOpQrStU']);
delete_option('analytics_connector_api_key'); delete_option('dev_analytics_status'); delete_option('dev_analytics_log');
analytics_connector_flush_reports(); update_user_meta(1, 'locale', '');` );

const browser = await chromium.launch();
const admin = await login( browser, 'admin', 'admin' );
const errors = [];
admin.on( 'pageerror', ( e ) => errors.push( String( e ) ) );
const bodies = recordBodies( admin );

// Without a key: what to do, in order.
await admin.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await admin.waitForSelector( 'text=Connect WordPress to your statistics' );
check( 'no key: the steps, with a link to the service’s keys', await admin.locator( '.anc-steps a[href="https://analytics.mock.test/settings/api-keys"]' ).count() === 1 );
check( 'no key: no period to choose', await admin.locator( '.anc-filters' ).count() === 0 );
await audit( admin, 'overview without a key' );

// Settings: paste the key.
await admin.getByRole( 'link', { name: 'Open Settings' } ).click();
await admin.waitForSelector( 'text=Paste the key' );
check( 'settings: the key section opens from the empty state', admin.url().endsWith( '#key' ) );
await admin.getByLabel( 'Paste the key' ).fill( 'not a key' );
await admin.getByRole( 'button', { name: 'Save changes' } ).click();
await admin.waitForSelector( 'text=That is not an API key' );
check( 'settings: a wrong key is refused in words', true );
await admin.getByLabel( 'Paste the key' ).fill( KEY );
await admin.getByRole( 'button', { name: 'Save changes' } ).click();
await admin.waitForSelector( 'text=Settings saved.' );
check( 'settings: the key is shown only by its beginning', await admin.locator( '.anc-key code' ).textContent() === 'ak_devMock1_…' );
const stored = php( `echo get_option('analytics_connector_api_key');` );
check( 'settings: stored encrypted', stored.length > 40 && ! stored.includes( SECRET ) && ! Buffer.from( stored, 'base64' ).toString( 'latin1' ).includes( SECRET ) );
await audit( admin, 'settings, key' );
await shot( admin, 'settings-key' );

await admin.reload();
await admin.waitForSelector( '.anc-key code' );
await admin.getByRole( 'button', { name: 'The site on the service' } ).click();
await admin.waitForSelector( 'text=The key reads this site’s reports' );
check( 'status: the tracker is served, with its banner and cookies', await admin.locator( 'text=Published · version 2 · IT, EN' ).count() === 1 && await admin.locator( 'text=After consent: returning visitors are recognised' ).count() === 1 );
await audit( admin, 'settings, the site on the service' );
await shot( admin, 'settings-site' );

// The Overview.
await admin.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await admin.waitForSelector( '.anc-chart svg' );
await admin.waitForSelector( 'text=/lavori/' );
await admin.waitForSelector( '.anc-now__count strong' );
check( 'overview: the numbers, with their change', ( await admin.locator( '.anc-stat' ).count() ) === 6 && /\+\d+%/.test( await admin.locator( '.anc-stat' ).first().textContent() ) );
check( 'overview: the lists', ( await admin.locator( '.anc-bars li' ).count() ) >= 15 && await admin.locator( 'text=Search engines' ).count() === 1 && await admin.locator( 'text=Phone' ).count() >= 1 );
check( 'overview: now on the site', await admin.locator( '.anc-now__count strong' ).textContent() === '3' );
check( 'overview: 30 days by default, 30 points', ( await admin.locator( '.anc-chart__hit' ).count() ) === 30 );
await admin.locator( '.anc-chart svg' ).focus();
await admin.keyboard.press( 'End' );
check( 'chart: read with the keyboard', /before:/.test( await admin.locator( '.anc-chart__readout' ).textContent() ) );
await admin.getByRole( 'button', { name: 'Pageviews', exact: false } ).first().click();
check( 'chart: another number drawn', await admin.locator( '.anc-chart__readout strong' ).first().textContent() === 'Pageviews per day' );
await admin.getByRole( 'button', { name: '7 days' } ).click();
await admin.waitForFunction( () => document.querySelectorAll( '.anc-chart__hit' ).length === 7 );
check( 'period: 7 days, remembered in the address', admin.url().endsWith( '#period=7d' ) );
await admin.getByRole( 'button', { name: 'Today' } ).click();
await admin.waitForFunction( () => document.querySelector( '.anc-chart__readout' )?.textContent.includes( 'per hour' ) );
check( 'period: today, by the hour', true );
await admin.getByRole( 'button', { name: '30 days' } ).click();
await admin.waitForFunction( () => document.querySelectorAll( '.anc-chart__hit' ).length === 30 );
await audit( admin, 'overview' );
await shot( admin, 'overview' );

const log = JSON.parse( php( `echo wp_json_encode(get_option('dev_analytics_log', []));` ) );
const reports = log.filter( ( r ) => r.path.includes( '/reports/' ) );
check( 'service: every report asked with the key, never following redirects', reports.length > 0 && reports.every( ( r ) => r.bearer && r.redirection === 0 ) );
check( 'service: only the whitelisted reports', reports.every( ( r ) => /\/reports\/(overview|timeseries|pages|sources|countries|tech|events|realtime)$/.test( r.path ) ) );
check( 'the key never reaches the browser', bodies.length > 20 && ! bodies.some( ( b ) => b.includes( SECRET ) ) && ! ( await admin.content() ).includes( SECRET ) );

// A phone.
const phone = await login( browser, 'admin', 'admin', { width: 390, height: 844 } );
await phone.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await phone.waitForSelector( '.anc-chart svg' );
check( 'phone: no sideways scrolling', await phone.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) );
await shot( phone, 'overview-phone' );

// The WordPress dashboard's widget.
await admin.goto( `${ BASE }/wp-admin/` );
check( 'widget: visitors today and over 7 days', await admin.locator( '#analytics_connector' ).count() === 1 && /Visitors today/.test( await admin.locator( '#analytics_connector' ).textContent() ) );
check( 'plugins: a Settings link', await ( async () => {
	await admin.goto( `${ BASE }/wp-admin/plugins.php` );
	return admin.locator( 'tr[data-plugin="analytics-connector/analytics-connector.php"] a[href$="page=analytics-settings"]' ).count();
} )() === 1 );

// The service refuses: a sentence, and the way out.
for ( const [ status, words ] of [ [ 403, 'cannot read reports' ], [ 401, 'refused the API key' ], [ 404, 'does not know this site' ] ] ) {
	php( `update_option('dev_analytics_status', ${ status }); analytics_connector_flush_reports();` );
	await admin.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
	await admin.waitForSelector( 'text=The statistics cannot be read yet' );
	check( `refusal ${ status }: in words, with the steps`, await admin.locator( `text=${ words }` ).count() === 1 && await admin.locator( '.anc-steps' ).count() === 1 );
}
php( `update_option('dev_analytics_status', 503); analytics_connector_flush_reports();` );
await admin.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await admin.waitForSelector( '.components-notice.is-error' );
check( 'service down: a notice, not the setup', await admin.locator( 'text=had a problem' ).count() >= 1 && await admin.locator( '.anc-steps' ).count() === 0 );
php( `delete_option('dev_analytics_status'); analytics_connector_flush_reports();` );

// An editor: the numbers, not the settings.
const editor = await login( browser, 'agenda_editor', 'secret-pass-1' );
await editor.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await editor.waitForSelector( '.anc-chart svg' );
check( 'editor: the Overview, without a Settings tab', await editor.locator( '.anc-tabs' ).count() === 0 );
const denied = await editor.goto( `${ BASE }/wp-admin/admin.php?page=analytics-settings` );
check( 'editor: no Settings page', denied.status() === 403 || /not allowed/i.test( await editor.content() ) );
const nonce = await editor.evaluate( () => window.wpApiSettings?.nonce || '' );
const rest = await editor.evaluate( async ( n ) => ( await fetch( '/wp-json/analytics-connector/v1/admin/settings', { headers: { 'X-WP-Nonce': n } } ) ).status, nonce );
check( 'editor: no settings through the API either', rest === 403, String( rest ) );

// Italian.
php( `update_user_meta(1, 'locale', 'it_IT');` );
await admin.goto( `${ BASE }/wp-admin/admin.php?page=analytics` );
await admin.waitForSelector( '.anc-chart svg' );
check( 'italian: the screen', await admin.locator( 'h1.anc-brand__title' ).textContent() === 'Panoramica' && await admin.locator( 'text=Motori di ricerca' ).count() === 1 );
await shot( admin, 'overview-it' );
php( `update_user_meta(1, 'locale', '');` );

check( 'no JavaScript errors', errors.length === 0, errors.join( ' | ' ) );
await browser.close();
console.log( failures ? `\n${ failures } failed` : '\nall passed' );
process.exit( failures ? 1 : 0 );
