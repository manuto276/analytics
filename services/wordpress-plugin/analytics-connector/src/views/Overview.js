/**
 * Overview: the site's statistics for a period, compared with the one before — the numbers,
 * one of them over time, where visits come from, what they read, and who is on the site now.
 *
 * Every report is its own call (WordPress → the service, cached a minute), so a slow one
 * does not hold the others back.
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	api,
	config,
	countryName,
	errorMessage,
	formatDelta,
	formatDuration,
	formatNumber,
	formatPercent,
	pageUrl,
} from '../api';
import { Card, Empty, Header, Loading } from '../components';
import LineChart from './Chart';

const periodList = () => [
	[ 'today', __( 'Today', 'analytics-connector' ), 'hour' ],
	[ '7d', __( '7 days', 'analytics-connector' ), 'day' ],
	[ '30d', __( '30 days', 'analytics-connector' ), 'day' ],
	[ '90d', __( '90 days', 'analytics-connector' ), 'week' ],
	[ '12mo', __( '12 months', 'analytics-connector' ), 'month' ],
];

/** The numbers at the top: which, how they read, and whether up is good. */
const metricList = () => [
	{
		key: 'visitors',
		label: __( 'Visitors', 'analytics-connector' ),
		chart: true,
	},
	{
		key: 'visits',
		label: __( 'Visits', 'analytics-connector' ),
		chart: true,
	},
	{
		key: 'pageviews',
		label: __( 'Pageviews', 'analytics-connector' ),
		chart: true,
	},
	{
		key: 'bounce_rate',
		label: __( 'Bounce rate', 'analytics-connector' ),
		format: formatPercent,
		lowerIsBetter: true,
	},
	{
		key: 'avg_duration_ms',
		label: __( 'Visit duration', 'analytics-connector' ),
		format: formatDuration,
	},
	{
		key: 'conversions',
		label: __( 'Conversions', 'analytics-connector' ),
		chart: true,
	},
];

const chartLabels = () => ( {
	visitors: {
		hour: __( 'Visitors per hour', 'analytics-connector' ),
		day: __( 'Visitors per day', 'analytics-connector' ),
		week: __( 'Visitors per week', 'analytics-connector' ),
		month: __( 'Visitors per month', 'analytics-connector' ),
	},
	visits: {
		hour: __( 'Visits per hour', 'analytics-connector' ),
		day: __( 'Visits per day', 'analytics-connector' ),
		week: __( 'Visits per week', 'analytics-connector' ),
		month: __( 'Visits per month', 'analytics-connector' ),
	},
	pageviews: {
		hour: __( 'Pageviews per hour', 'analytics-connector' ),
		day: __( 'Pageviews per day', 'analytics-connector' ),
		week: __( 'Pageviews per week', 'analytics-connector' ),
		month: __( 'Pageviews per month', 'analytics-connector' ),
	},
	conversions: {
		hour: __( 'Conversions per hour', 'analytics-connector' ),
		day: __( 'Conversions per day', 'analytics-connector' ),
		week: __( 'Conversions per week', 'analytics-connector' ),
		month: __( 'Conversions per month', 'analytics-connector' ),
	},
} );

const channelLabels = () => ( {
	direct: __( 'Direct', 'analytics-connector' ),
	organic_search: __( 'Search engines', 'analytics-connector' ),
	paid_search: __( 'Paid search', 'analytics-connector' ),
	organic_social: __( 'Social networks', 'analytics-connector' ),
	paid_social: __( 'Paid social', 'analytics-connector' ),
	email: __( 'Email', 'analytics-connector' ),
	referral: __( 'Other sites', 'analytics-connector' ),
	campaign: __( 'Campaigns', 'analytics-connector' ),
	internal: __( 'Internal', 'analytics-connector' ),
} );

const deviceLabels = () => ( {
	desktop: __( 'Computer', 'analytics-connector' ),
	mobile: __( 'Phone', 'analytics-connector' ),
	tablet: __( 'Tablet', 'analytics-connector' ),
} );

/** The errors that mean "set it up first", not "try again". */
const SETUP_ERRORS = [
	'analytics_not_connected',
	'analytics_no_key',
	'analytics_key_invalid',
	'analytics_key_scope',
	'analytics_not_found',
];

/**
 * A report, loaded when its parameters change: { data, error }.
 */
function useReport( name, query, enabled = true ) {
	const [ state, setState ] = useState( { data: null, error: null } );
	const key = JSON.stringify( query );
	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		let live = true;
		setState( { data: null, error: null } );
		api( `/reports/${ name }`, { query } ).then(
			( res ) =>
				live &&
				setState( { data: res.data, meta: res.meta, error: null } ),
			( e ) => live && setState( { data: null, error: e } )
		);
		return () => {
			live = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ name, key, enabled ] );
	return state;
}

export default function Overview() {
	const initial = new URLSearchParams( window.location.hash.slice( 1 ) ).get(
		'period'
	);
	const [ period, setPeriod ] = useState(
		periodList().some( ( [ p ] ) => p === initial ) ? initial : '30d'
	);
	const [ metric, setMetric ] = useState( 'visitors' );
	const ready = !! ( config.connected && config.hasKey );
	const interval = periodList().find( ( [ p ] ) => p === period )[ 2 ];

	useEffect( () => {
		window.history.replaceState( {}, '', `#period=${ period }` );
	}, [ period ] );

	const overview = useReport(
		'overview',
		{ period, compare: 'previous_period' },
		ready
	);
	const series = useReport(
		'timeseries',
		{ period, interval, compare: 'previous_period' },
		ready
	);

	const setup = ! ready || SETUP_ERRORS.includes( overview.error?.code );

	return (
		<div className="anc-app">
			<Header
				view="overview"
				title={ __( 'Overview', 'analytics-connector' ) }
				actions={
					! setup && (
						<div
							className="anc-filters"
							role="group"
							aria-label={ __( 'Period', 'analytics-connector' ) }
						>
							{ periodList().map( ( [ key, label ] ) => (
								<button
									key={ key }
									type="button"
									className={
										'anc-filter' +
										( period === key ? ' is-active' : '' )
									}
									aria-pressed={ period === key }
									onClick={ () => setPeriod( key ) }
								>
									{ label }
								</button>
							) ) }
						</div>
					)
				}
			/>
			{ setup ? (
				<Setup error={ overview.error } />
			) : (
				<>
					{ overview.error && (
						<Notice status="error" isDismissible={ false }>
							{ errorMessage( overview.error ) }
						</Notice>
					) }
					<div className="anc-dashboard">
						<Numbers
							report={ overview }
							metric={ metric }
							onPick={ setMetric }
						/>
						<Card className="anc-dashboard__wide anc-dashboard__chart">
							{ series.error && (
								<p className="anc-muted">
									{ errorMessage( series.error ) }
								</p>
							) }
							{ ! series.data && ! series.error && <Loading /> }
							{ series.data && (
								<LineChart
									points={ series.data.points }
									compare={ series.data.compare_points }
									metric={ metric }
									interval={ interval }
									label={
										chartLabels()[ metric ][ interval ]
									}
								/>
							) }
						</Card>
						<Realtime />
						<ListCard
							title={ __( 'Pages', 'analytics-connector' ) }
							subtitle={ __(
								'The most read',
								'analytics-connector'
							) }
							name="pages"
							query={ { period, kind: 'top', limit: 8 } }
							label={ ( r ) => r.path || '/' }
							value={ ( r ) => r.pageviews }
							valueLabel={ __(
								'Pageviews',
								'analytics-connector'
							) }
							wide
						/>
						<ListCard
							title={ __( 'Sources', 'analytics-connector' ) }
							subtitle={ __(
								'Where visits come from',
								'analytics-connector'
							) }
							name="sources"
							query={ { period, group: 'channel', limit: 8 } }
							label={ ( r ) =>
								channelLabels()[ r.channel ] ||
								r.channel ||
								__( 'Unknown', 'analytics-connector' )
							}
							value={ ( r ) => r.visits }
							valueLabel={ __( 'Visits', 'analytics-connector' ) }
						/>
						<ListCard
							title={ __( 'Countries', 'analytics-connector' ) }
							name="countries"
							query={ { period, limit: 8 } }
							label={ ( r ) => countryName( r.country ) }
							value={ ( r ) => r.visits }
							valueLabel={ __( 'Visits', 'analytics-connector' ) }
						/>
						<ListCard
							title={ __( 'Devices', 'analytics-connector' ) }
							name="tech"
							query={ { period, group: 'device', limit: 8 } }
							label={ ( r ) =>
								deviceLabels()[ r.value ] ||
								r.value ||
								__( 'Unknown', 'analytics-connector' )
							}
							value={ ( r ) => r.visits }
							valueLabel={ __( 'Visits', 'analytics-connector' ) }
						/>
						<ListCard
							title={ __( 'Events', 'analytics-connector' ) }
							subtitle={ __(
								'Clicks, forms, downloads you track',
								'analytics-connector'
							) }
							name="events"
							query={ { period, limit: 8 } }
							label={ ( r ) => r.name }
							value={ ( r ) => r.occurrences }
							valueLabel={ __( 'Times', 'analytics-connector' ) }
							empty={ __(
								'No events in this period. The tracker sends them for the links, forms and downloads enabled on the service, and for analytics.track() calls.',
								'analytics-connector'
							) }
						/>
					</div>
					{ config.service && (
						<p className="anc-footnote">
							<a
								href={ config.service }
								target="_blank"
								rel="noreferrer"
							>
								{ __(
									'Everything else — campaigns, goals, funnels — on the analytics service',
									'analytics-connector'
								) }
								<span className="screen-reader-text">
									{ ' ' }
									{ __(
										'(opens in a new tab)',
										'analytics-connector'
									) }
								</span>
							</a>
						</p>
					) }
				</>
			) }
		</div>
	);
}

/**
 * The six numbers; the ones that can be drawn choose the chart.
 */
function Numbers( { report, metric, onPick } ) {
	const { data } = report;
	return (
		<div
			className="anc-numbers anc-dashboard__full"
			role="group"
			aria-label={ __( 'The period in numbers', 'analytics-connector' ) }
		>
			{ metricList().map( ( m ) => {
				const format = m.format || formatNumber;
				const value = data?.metrics?.[ m.key ];
				const delta = data?.deltas?.[ m.key ];
				const better =
					delta === null || delta === undefined || delta === 0
						? ''
						: delta > 0 !== !! m.lowerIsBetter
						? ' is-up'
						: ' is-down';
				const inner = (
					<>
						<span className="anc-stat__label">{ m.label }</span>
						<span className="anc-stat__value">
							{ data ? format( value ) : '…' }
						</span>
						<span className={ 'anc-stat__note' + better }>
							{ delta === null || delta === undefined
								? data?.compare
									? sprintf(
											/* translators: %s: the value in the previous period. */ __(
												'before: %s',
												'analytics-connector'
											),
											format( data.compare[ m.key ] )
									  )
									: ' '
								: sprintf(
										/* translators: %s: a change, e.g. "+12%". */ __(
											'%s on the previous period',
											'analytics-connector'
										),
										formatDelta( delta )
								  ) }
						</span>
					</>
				);
				return m.chart ? (
					<button
						key={ m.key }
						type="button"
						className={
							'anc-stat' +
							( metric === m.key ? ' is-active' : '' )
						}
						aria-pressed={ metric === m.key }
						onClick={ () => onPick( m.key ) }
					>
						{ inner }
					</button>
				) : (
					<div key={ m.key } className="anc-stat">
						{ inner }
					</div>
				);
			} ) }
		</div>
	);
}

/**
 * A report's rows as bars.
 */
function ListCard( {
	title,
	subtitle,
	name,
	query,
	label,
	value,
	valueLabel,
	wide = false,
	empty,
} ) {
	const { data, error } = useReport( name, query );
	const rows = data?.rows || [];
	const max = Math.max( 1, ...rows.map( value ) );
	return (
		<Card
			title={ title }
			subtitle={ subtitle }
			className={ wide ? 'anc-dashboard__wide' : '' }
		>
			{ error && <p className="anc-muted">{ errorMessage( error ) }</p> }
			{ ! data && ! error && <Loading /> }
			{ data && rows.length === 0 && (
				<p className="anc-muted">
					{ empty ||
						__( 'Nothing in this period.', 'analytics-connector' ) }
				</p>
			) }
			{ rows.length > 0 && (
				<ul
					className="anc-bars"
					aria-label={ sprintf(
						/* translators: 1: a list, e.g. "Pages", 2: what the numbers count, e.g. "Pageviews". */ __(
							'%1$s, by %2$s',
							'analytics-connector'
						),
						title,
						valueLabel.toLowerCase()
					) }
				>
					{ rows.map( ( r, i ) => (
						<li key={ i }>
							<span
								className="anc-bars__label"
								title={ label( r ) }
							>
								{ label( r ) }
							</span>
							<span
								className="anc-bars__track"
								aria-hidden="true"
							>
								<span
									style={ {
										width: `${
											( value( r ) / max ) * 100
										}%`,
									} }
								/>
							</span>
							<span className="anc-bars__value">
								{ formatNumber( value( r ) ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</Card>
	);
}

/** Who is on the site now: refreshed every 30 seconds while the page is in view. */
function Realtime() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		let timer;
		const load = () => {
			if ( document.visibilityState === 'visible' ) {
				api( '/reports/realtime' ).then( ( res ) => {
					setData( res.data );
					setError( null );
				}, setError );
			}
			timer = setTimeout( load, 30000 );
		};
		load();
		const onVisible = () =>
			document.visibilityState === 'visible' &&
			( clearTimeout( timer ), load() );
		document.addEventListener( 'visibilitychange', onVisible );
		return () => {
			clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', onVisible );
		};
	}, [] );

	const active = data?.active_visitors ?? 0;
	return (
		<Card
			title={ __( 'Now', 'analytics-connector' ) }
			subtitle={ __(
				'The last 5 minutes, updated every 30 seconds',
				'analytics-connector'
			) }
			className="anc-now"
		>
			{ error && <p className="anc-muted">{ errorMessage( error ) }</p> }
			{ ! data && ! error && <Loading /> }
			{ data && (
				<>
					<p className="anc-now__count">
						<span
							className={
								'anc-now__dot' + ( active ? ' is-live' : '' )
							}
							aria-hidden="true"
						/>
						<strong>{ formatNumber( active ) }</strong>{ ' ' }
						{ _n(
							'visitor on the site',
							'visitors on the site',
							active,
							'analytics-connector'
						) }
					</p>
					{ data.top_pages.length > 0 && (
						<ul className="anc-ways">
							{ data.top_pages.slice( 0, 5 ).map( ( p ) => (
								<li key={ p.host + p.path }>
									<span className="anc-bars__label">
										{ p.path }
									</span>
									<strong>
										{ formatNumber( p.pageviews ) }
									</strong>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
		</Card>
	);
}

/**
 * No key yet, or one the service refuses: what to do, in order.
 */
function Setup( { error } ) {
	const manage = !! config.can?.manage;
	const title = error
		? __( 'The statistics cannot be read yet', 'analytics-connector' )
		: __( 'Connect WordPress to your statistics', 'analytics-connector' );
	return (
		<div className="anc-setup">
			<Card>
				<Empty
					title={ title }
					action={
						manage ? (
							<Button
								variant="primary"
								href={ pageUrl( 'settings', {} ) + '#key' }
							>
								{ __( 'Open Settings', 'analytics-connector' ) }
							</Button>
						) : null
					}
				>
					{ error && <p>{ errorMessage( error ) }</p> }
					{ manage ? (
						<ol className="anc-steps">
							{ ! config.connected && (
								<li>
									{ __(
										'In Settings → Connection, enter the address of the analytics service and the site’s public key (pk_…).',
										'analytics-connector'
									) }
								</li>
							) }
							<li>
								{ config.service ? (
									<a
										href={ `${ config.service }/settings/api-keys` }
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'On the analytics service, create an API key with the “Read reports” permission',
											'analytics-connector'
										) }
										<span className="screen-reader-text">
											{ ' ' }
											{ __(
												'(opens in a new tab)',
												'analytics-connector'
											) }
										</span>
									</a>
								) : (
									__(
										'On the analytics service, create an API key with the “Read reports” permission',
										'analytics-connector'
									)
								) }
								.
							</li>
							<li>
								{ __(
									'Paste it in Settings → API key. It stays on the server: the browser never sees it.',
									'analytics-connector'
								) }
							</li>
						</ol>
					) : (
						<p>
							{ __(
								'An administrator has to connect the site to the analytics service first.',
								'analytics-connector'
							) }
						</p>
					) }
				</Empty>
			</Card>
		</div>
	);
}
