/**
 * Pieces shared by the views.
 */
import { Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { config, pageUrl } from './api';

const tabList = () => [
	[ 'overview', __( 'Overview', 'analytics-connector' ) ],
	[ 'settings', __( 'Settings', 'analytics-connector' ) ],
];

/**
 * The header: icon, name, and the views as tabs.
 */
export function Header( { view, title, actions } ) {
	// Only the views this user may open: the server lists them in config.pages.
	const tabs = tabList().filter( ( [ key ] ) => config.pages?.[ key ] );

	return (
		<header className="anc-header">
			<div className="anc-header__top">
				<div className="anc-brand">
					<img src={ config.icon } alt="" width="44" height="44" />
					<div>
						<span className="anc-brand__kicker">
							{ sprintf(
								/* translators: %s: site name. */
								__( 'Analytics · %s', 'analytics-connector' ),
								config.site
							) }
						</span>
						<h1 className="anc-brand__title">{ title }</h1>
					</div>
				</div>
				{ actions && (
					<div className="anc-header__actions">{ actions }</div>
				) }
			</div>
			{ tabs.length > 1 && (
				<nav
					className="anc-tabs"
					aria-label={ __(
						'Analytics sections',
						'analytics-connector'
					) }
				>
					{ tabs.map( ( [ key, label ] ) => (
						<a
							key={ key }
							href={ pageUrl( key ) }
							className={
								'anc-tab' + ( key === view ? ' is-active' : '' )
							}
							aria-current={ key === view ? 'page' : undefined }
						>
							{ label }
						</a>
					) ) }
				</nav>
			) }
		</header>
	);
}

export function Card( {
	title,
	subtitle,
	actions,
	children,
	className = '',
	flush = false,
} ) {
	return (
		<section className={ `anc-card ${ className }` }>
			{ ( title || actions ) && (
				<div className="anc-card__head">
					<div>
						{ title && (
							<h2 className="anc-card__title">{ title }</h2>
						) }
						{ subtitle && (
							<p className="anc-card__subtitle">{ subtitle }</p>
						) }
					</div>
					{ actions && (
						<div className="anc-card__actions">{ actions }</div>
					) }
				</div>
			) }
			<div
				className={
					flush ? 'anc-card__body is-flush' : 'anc-card__body'
				}
			>
				{ children }
			</div>
		</section>
	);
}

export function Loading( { label = __( 'Loading…', 'analytics-connector' ) } ) {
	return (
		<div className="anc-loading">
			<Spinner />
			<span>{ label }</span>
		</div>
	);
}

export function Empty( { title, children, action } ) {
	return (
		<div className="anc-empty">
			<p className="anc-empty__title">{ title }</p>
			{ children && <div className="anc-empty__text">{ children }</div> }
			{ action }
		</div>
	);
}

/** A state as a coloured badge: ok (green), warn (amber), off (grey), error (red). */
const BADGE_CLASS = {
	ok: 'is-sent',
	warn: 'is-sending',
	off: 'is-draft',
	error: 'is-cancelled',
};
export function Badge( { tone = 'off', children } ) {
	return (
		<span className={ `anc-badge ${ BADGE_CLASS[ tone ] || '' }` }>
			{ children }
		</span>
	);
}
