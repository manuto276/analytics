import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const ROOT = '/analytics-connector/v1/admin';

export const config = window.analyticsConnectorAdmin || {};

/** The admin user's locale, as WordPress declares it on <html> (e.g. "it-IT"). */
export const locale = document.documentElement.lang || 'en-US';

export function api( path, { method = 'GET', data, query } = {} ) {
	return apiFetch( {
		path: addQueryArgs( ROOT + path, query ),
		method,
		data,
	} );
}

/**
 * The API's error message, or a generic one when the server gave none.
 */
export function errorMessage( error ) {
	return (
		error?.message ||
		__( 'Something went wrong. Please try again.', 'analytics-connector' )
	);
}

const numberFormat = new Intl.NumberFormat( locale );
export const formatNumber = ( n ) =>
	n === null || n === undefined ? '—' : numberFormat.format( n );

const compactFormat = new Intl.NumberFormat( locale, {
	notation: 'compact',
	maximumFractionDigits: 1,
} );
export const formatCompact = ( n ) =>
	n === null || n === undefined ? '—' : compactFormat.format( n );

const percentFormat = new Intl.NumberFormat( locale, {
	style: 'percent',
	maximumFractionDigits: 0,
} );
/**
 * A fraction 0–1 as "42%".
 */
export const formatPercent = ( f ) =>
	f === null || f === undefined ? '—' : percentFormat.format( f );

const deltaFormat = new Intl.NumberFormat( locale, {
	style: 'percent',
	maximumFractionDigits: 0,
	signDisplay: 'exceptZero',
} );
/**
 * A relative change as "+25%" / "−10%".
 */
export const formatDelta = ( f ) => deltaFormat.format( f );

/**
 * Milliseconds as "1 min 05 s" / "42 s".
 */
export function formatDuration( ms ) {
	if ( ms === null || ms === undefined ) {
		return '—';
	}
	const s = Math.round( ms / 1000 );
	if ( s < 60 ) {
		return new Intl.NumberFormat( locale, {
			style: 'unit',
			unit: 'second',
			unitDisplay: 'narrow',
		} ).format( s );
	}
	const m = Math.floor( s / 60 );
	return `${ new Intl.NumberFormat( locale, {
		style: 'unit',
		unit: 'minute',
		unitDisplay: 'narrow',
	} ).format( m ) } ${ String( s % 60 ).padStart( 2, '0' ) }s`;
}

/**
 * A report's day ("2026-09-17") or hour ("2026-09-17 14:00"), as a Date at that local time.
 */
export const pointDate = ( t ) =>
	new Date( t.length > 10 ? t.replace( ' ', 'T' ) : `${ t }T12:00:00` );

export const formatDay = ( t, opts ) =>
	new Intl.DateTimeFormat( locale, opts ).format( pointDate( t ) );

/**
 * A country code as its name in the user's language: "IT" → "Italia".
 */
export function countryName( code ) {
	if ( ! code ) {
		return __( 'Unknown', 'analytics-connector' );
	}
	try {
		return (
			new Intl.DisplayNames( [ locale ], { type: 'region' } ).of(
				code
			) || code
		);
	} catch ( e ) {
		return code;
	}
}

export function pageUrl( view, params = {} ) {
	const url = new URL( config.pages?.[ view ] || window.location.href );
	Object.entries( params ).forEach( ( [ k, v ] ) =>
		url.searchParams.set( k, v )
	);
	return url.toString();
}
