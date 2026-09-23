/**
 * One metric over the period: a line with its area, the previous period dashed behind it,
 * and the value of a point on hover or with the arrow keys. Hand-written SVG, from Agenda's
 * chart: one chart does not justify a library.
 */
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formatDay, formatNumber } from '../api';

const H = 220;
const PAD = { top: 16, right: 12, bottom: 28, left: 40 };

/**
 * A "round" axis maximum: twice a step of 1, 2 or 5.
 */
function niceMax( max ) {
	if ( max <= 4 ) {
		return 4;
	}
	const half = max / 2;
	const pow = Math.pow( 10, Math.floor( Math.log10( half ) ) );
	const n = half / pow;
	return ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * pow * 2;
}

/**
 * How a point's time reads: the hour for a day's chart, the day otherwise.
 */
function pointLabel( t, interval, long ) {
	if ( interval === 'hour' ) {
		return formatDay(
			t,
			long
				? { weekday: 'long', hour: '2-digit', minute: '2-digit' }
				: { hour: '2-digit' }
		);
	}
	if ( interval === 'month' ) {
		return formatDay(
			t,
			long ? { month: 'long', year: 'numeric' } : { month: 'short' }
		);
	}
	return formatDay(
		t,
		long
			? { weekday: 'long', day: 'numeric', month: 'long' }
			: { day: 'numeric', month: 'short' }
	);
}

export default function LineChart( {
	points,
	compare,
	metric,
	label,
	interval,
	format = formatNumber,
} ) {
	const [ hover, setHover ] = useState( null );
	// Drawn at the box's own width, so text stays text-sized on a phone.
	const box = useRef();
	const [ W, setW ] = useState( 720 );
	useEffect( () => {
		const observer = new window.ResizeObserver( ( [ entry ] ) =>
			setW( Math.max( 280, Math.round( entry.contentRect.width ) ) )
		);
		observer.observe( box.current );
		return () => observer.disconnect();
	}, [] );
	const value = ( p ) => p?.[ metric ] ?? 0;
	const n = points.length;
	const max = niceMax(
		Math.max( 0, ...points.map( value ), ...( compare || [] ).map( value ) )
	);
	const innerW = W - PAD.left - PAD.right;
	const innerH = H - PAD.top - PAD.bottom;
	const x = ( i ) =>
		PAD.left + ( n > 1 ? ( i / ( n - 1 ) ) * innerW : innerW / 2 );
	const y = ( v ) => PAD.top + innerH - ( v / max ) * innerH;
	const path = ( list ) =>
		list
			.map(
				( p, i ) =>
					`${ i ? 'L' : 'M' }${ x( i ).toFixed( 1 ) },${ y(
						value( p )
					).toFixed( 1 ) }`
			)
			.join( '' );
	const line = path( points );
	const area = n
		? `${ line }L${ x( n - 1 ).toFixed( 1 ) },${ y( 0 ) }L${ x( 0 ).toFixed(
				1
		  ) },${ y( 0 ) }Z`
		: '';
	const ticks = [ 0, max / 2, max ];
	const step = Math.max(
		1,
		Math.ceil( n / Math.max( 3, Math.floor( W / 120 ) ) )
	);
	const active = hover !== null ? points[ hover ] : null;
	const before = hover !== null && compare ? compare[ hover ] : null;

	const onKeyDown = ( e ) => {
		if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
			e.preventDefault();
			const from =
				hover === null ? ( e.key === 'ArrowRight' ? -1 : n ) : hover;
			setHover(
				Math.min(
					n - 1,
					Math.max( 0, from + ( e.key === 'ArrowRight' ? 1 : -1 ) )
				)
			);
		} else if ( e.key === 'Home' || e.key === 'End' ) {
			e.preventDefault();
			setHover( e.key === 'Home' ? 0 : n - 1 );
		} else if ( e.key === 'Escape' ) {
			setHover( null );
		}
	};

	return (
		<div className="anc-chart" ref={ box }>
			<div className="anc-chart__readout" aria-live="polite">
				{ active ? (
					createInterpolateElement(
						before
							? sprintf(
									/* translators: 1: a value, 2: a day or hour, 3: the value in the previous period. */
									__(
										'<strong>%1$s</strong> · %2$s <span>(before: %3$s)</span>',
										'analytics-connector'
									),
									format( value( active ) ),
									pointLabel( active.t, interval, true ),
									format( value( before ) )
							  )
							: sprintf(
									/* translators: 1: a value, 2: a day or hour. */
									__(
										'<strong>%1$s</strong> · %2$s',
										'analytics-connector'
									),
									format( value( active ) ),
									pointLabel( active.t, interval, true )
							  ),
						{
							strong: <strong />,
							span: <span className="anc-chart__before" />,
						}
					)
				) : (
					<>
						<strong>{ label }</strong>
						<span className="anc-chart__hint">
							{ ' ' }
							·{ ' ' }
							{ __(
								'point at the chart, or focus it and use the arrow keys',
								'analytics-connector'
							) }
						</span>
					</>
				) }
			</div>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- a chart read with the arrow keys. */ }
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				role="img"
				tabIndex={ 0 }
				aria-label={ sprintf(
					/* translators: 1: the metric, e.g. "Visitors per day", 2: the number of points. */
					__(
						'%1$s: %2$s points. Use the arrow keys to read each one.',
						'analytics-connector'
					),
					label,
					n
				) }
				onMouseLeave={ () => setHover( null ) }
				onKeyDown={ onKeyDown }
				onBlur={ () => setHover( null ) }
			>
				<defs>
					<linearGradient id="anc-area" x1="0" x2="0" y1="0" y2="1">
						<stop
							offset="0"
							stopColor="currentColor"
							stopOpacity="0.22"
						/>
						<stop
							offset="1"
							stopColor="currentColor"
							stopOpacity="0"
						/>
					</linearGradient>
				</defs>
				{ ticks.map( ( t ) => (
					<g key={ t }>
						<line
							className="anc-chart__grid"
							x1={ PAD.left }
							x2={ W - PAD.right }
							y1={ y( t ) }
							y2={ y( t ) }
						/>
						<text
							className="anc-chart__tick"
							x={ PAD.left - 8 }
							y={ y( t ) }
							dy="0.32em"
							textAnchor="end"
						>
							{ format( t ) }
						</text>
					</g>
				) ) }
				{ compare && compare.length === n && (
					<path
						className="anc-chart__compare"
						d={ path( compare ) }
					/>
				) }
				<path className="anc-chart__area" d={ area } />
				<path className="anc-chart__line" d={ line } />
				{ points.map( ( p, i ) => (
					<g key={ p.t }>
						<rect
							className="anc-chart__hit"
							x={ x( i ) - innerW / Math.max( 1, n - 1 ) / 2 }
							y={ PAD.top }
							width={ innerW / Math.max( 1, n - 1 ) }
							height={ innerH }
							onMouseEnter={ () => setHover( i ) }
						/>
						{ ( i % step === 0 || i === n - 1 ) &&
							( i === n - 1 || n - 1 - i >= step / 2 ) && (
								<text
									className="anc-chart__tick"
									x={ x( i ) }
									y={ H - 8 }
									textAnchor={
										i === 0
											? 'start'
											: i === n - 1
											? 'end'
											: 'middle'
									}
								>
									{ pointLabel( p.t, interval, false ) }
								</text>
							) }
					</g>
				) ) }
				{ active && (
					<g className="anc-chart__cursor">
						<line
							x1={ x( hover ) }
							x2={ x( hover ) }
							y1={ PAD.top }
							y2={ y( 0 ) }
						/>
						<circle
							cx={ x( hover ) }
							cy={ y( value( active ) ) }
							r="4.5"
						/>
					</g>
				) }
			</svg>
		</div>
	);
}
