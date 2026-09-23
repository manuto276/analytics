/**
 * Settings, laid out like the workspace's other plugins: sections on the side, one Save.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { api, errorMessage, formatNumber } from '../api';
import { Badge, Card, Header, Loading } from '../components';

const common = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };
const sectionList = () => [
	[ 'connection', __( 'Connection', 'analytics-connector' ) ],
	[ 'key', __( 'API key', 'analytics-connector' ) ],
	[ 'counting', __( 'Who is counted', 'analytics-connector' ) ],
	[ 'site', __( 'The site on the service', 'analytics-connector' ) ],
	[ 'consent', __( 'Consent and events', 'analytics-connector' ) ],
];

export default function Settings() {
	const hash = window.location.hash.slice( 1 );
	const [ section, setSection ] = useState(
		sectionList().some( ( [ k ] ) => k === hash ) ? hash : 'connection'
	);
	const [ data, setData ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ key, setKey ] = useState( { api_key: '', remove_api_key: false } );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const take = ( res ) => {
		setData( res );
		setDraft( { ...res.settings } );
		setKey( { api_key: '', remove_api_key: false } );
	};
	useEffect( () => {
		api( '/settings' ).then( take, ( e ) =>
			setNotice( { status: 'error', text: errorMessage( e ) } )
		);
	}, [] );
	useEffect( () => {
		window.history.replaceState( {}, '', `#${ section }` );
	}, [ section ] );
	const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );
	const dirty =
		!! data &&
		( JSON.stringify( data.settings ) !== JSON.stringify( draft ) ||
			key.api_key !== '' ||
			key.remove_api_key );
	const save = async () => {
		setBusy( true );
		try {
			take(
				await api( '/settings', {
					method: 'POST',
					data: { ...draft, ...key },
				} )
			);
			setNotice( {
				status: 'success',
				text: __( 'Settings saved.', 'analytics-connector' ),
			} );
		} catch ( e ) {
			setNotice( { status: 'error', text: errorMessage( e ) } );
		}
		setBusy( false );
	};
	if ( ! draft ) {
		return (
			<div className="anc-app">
				<Header
					view="settings"
					title={ __( 'Settings', 'analytics-connector' ) }
				/>
				{ notice ? (
					<Notice status="error" isDismissible={ false }>
						{ notice.text }
					</Notice>
				) : (
					<Loading />
				) }
			</div>
		);
	}
	const props = { draft, set, data, keyDraft: key, setKey };

	return (
		<div className="anc-app">
			<Header
				view="settings"
				title={ __( 'Settings', 'analytics-connector' ) }
				actions={
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ busy }
						disabled={ busy || ! dirty }
					>
						{ dirty
							? __( 'Save changes', 'analytics-connector' )
							: __( 'Saved', 'analytics-connector' ) }
					</Button>
				}
			/>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					className="anc-notice"
				>
					{ notice.text }
				</Notice>
			) }
			<div className="anc-settings">
				<nav
					className="anc-side"
					aria-label={ __(
						'Settings sections',
						'analytics-connector'
					) }
				>
					{ sectionList().map( ( [ k, label ] ) => (
						<button
							key={ k }
							type="button"
							className={
								'anc-side__item' +
								( section === k ? ' is-active' : '' )
							}
							aria-current={ section === k ? 'true' : undefined }
							onClick={ () => setSection( k ) }
						>
							{ label }
						</button>
					) ) }
				</nav>
				<div className="anc-settings__main">
					{ section === 'connection' && <Connection { ...props } /> }
					{ section === 'key' && <ApiKey { ...props } /> }
					{ section === 'counting' && <Counting { ...props } /> }
					{ section === 'site' && (
						<SiteStatus saved={ data.settings } />
					) }
					{ section === 'consent' && <Consent /> }
				</div>
			</div>
		</div>
	);
}

function Connection( { draft, set } ) {
	return (
		<Card
			title={ __( 'Connection', 'analytics-connector' ) }
			subtitle={ __(
				'Where the tracker comes from. Every page of the site loads it, with the consent banner when the service has one published.',
				'analytics-connector'
			) }
		>
			<div className="anc-fields">
				<TextControl
					{ ...common }
					type="url"
					label={ __( 'Service address', 'analytics-connector' ) }
					placeholder="https://analytics.example.com"
					value={ draft.service_url }
					onChange={ ( v ) => set( { service_url: v } ) }
					help={ __(
						'The address of your analytics service, without a path.',
						'analytics-connector'
					) }
				/>
				<TextControl
					{ ...common }
					label={ __( 'Public key', 'analytics-connector' ) }
					placeholder="pk_…"
					value={ draft.public_key }
					onChange={ ( v ) => set( { public_key: v.trim() } ) }
					help={ __(
						'The site’s key on the service, the pk_… in its tracking code. It is public: it is in every page.',
						'analytics-connector'
					) }
				/>
				<RadioControl
					label={ __(
						'How the tracker is loaded',
						'analytics-connector'
					) }
					selected={ draft.mode }
					onChange={ ( mode ) => set( { mode } ) }
					options={ [
						{
							value: 'direct',
							label: __(
								'From the service',
								'analytics-connector'
							),
						},
						{
							value: 'proxy',
							label: __(
								'Through a path of this site (a reverse proxy you set up on the web server)',
								'analytics-connector'
							),
						},
					] }
				/>
				{ draft.mode === 'proxy' && (
					<TextControl
						{ ...common }
						label={ __( 'Proxy path', 'analytics-connector' ) }
						placeholder="/stats/"
						value={ draft.proxy_path }
						onChange={ ( v ) => set( { proxy_path: v } ) }
						help={ __(
							'The script is then loaded from this site: {path}{public key}.js.',
							'analytics-connector'
						) }
					/>
				) }
				<TextControl
					{ ...common }
					label={ __( 'JavaScript name', 'analytics-connector' ) }
					value={ draft.global_name }
					onChange={ ( v ) => set( { global_name: v } ) }
					help={ __(
						'The global the site’s scripts call, as in analytics.track( … ). Change it only if another script already uses that name.',
						'analytics-connector'
					) }
				/>
			</div>
		</Card>
	);
}

function ApiKey( { data, keyDraft, setKey } ) {
	const { hint, from_config: fromConfig } = data.api_key;
	return (
		<Card
			title={ __( 'API key', 'analytics-connector' ) }
			subtitle={ __(
				'With a key that can read reports, Analytics → Overview shows the site’s statistics here, without going to the service.',
				'analytics-connector'
			) }
		>
			<div className="anc-fields">
				{ hint && (
					<p className="anc-key">
						<Badge
							tone={ keyDraft.remove_api_key ? 'error' : 'ok' }
						>
							{ keyDraft.remove_api_key
								? __( 'To remove', 'analytics-connector' )
								: __( 'Saved', 'analytics-connector' ) }
						</Badge>
						<code>{ hint }</code>
						{ fromConfig ? (
							<span className="anc-hint">
								{ __(
									'Set in wp-config.php (ANALYTICS_CONNECTOR_API_KEY): change it there.',
									'analytics-connector'
								) }
							</span>
						) : (
							<Button
								variant="link"
								isDestructive={ ! keyDraft.remove_api_key }
								onClick={ () =>
									setKey( {
										api_key: '',
										remove_api_key:
											! keyDraft.remove_api_key,
									} )
								}
							>
								{ keyDraft.remove_api_key
									? __( 'Keep it', 'analytics-connector' )
									: __( 'Remove', 'analytics-connector' ) }
							</Button>
						) }
					</p>
				) }
				{ ! fromConfig && (
					<TextControl
						{ ...common }
						type="password"
						autoComplete="off"
						spellCheck={ false }
						label={
							hint
								? __(
										'Replace with a new key',
										'analytics-connector'
								  )
								: __( 'Paste the key', 'analytics-connector' )
						}
						placeholder="ak_…"
						value={ keyDraft.api_key }
						onChange={ ( v ) =>
							setKey( {
								api_key: v.trim(),
								remove_api_key: false,
							} )
						}
						help={ __(
							'On the service: Settings → API keys, a new key with the “Read reports” permission. The key stays on this server, encrypted; the page shows only its beginning.',
							'analytics-connector'
						) }
					/>
				) }
				<p className="anc-hint">
					{ __(
						'Who sees the statistics: administrators and editors (the analytics_view capability). Who changes these settings: administrators (analytics_manage).',
						'analytics-connector'
					) }
				</p>
			</div>
		</Card>
	);
}

function Counting( { draft, set } ) {
	return (
		<Card
			title={ __( 'Who is counted', 'analytics-connector' ) }
			subtitle={ __(
				'The site’s own people, logged in, do not load the tracker: their visits are not counted.',
				'analytics-connector'
			) }
		>
			<div className="anc-fields">
				<TextControl
					{ ...common }
					label={ __(
						'Not counted: logged-in users who can',
						'analytics-connector'
					) }
					value={ draft.skip_capability }
					onChange={ ( v ) => set( { skip_capability: v } ) }
					help={ __(
						'A WordPress capability: edit_posts (the default) leaves out authors, editors and administrators; manage_options only administrators. Empty counts everyone.',
						'analytics-connector'
					) }
				/>
				<p className="anc-hint">
					{ __(
						'The service leaves out bots, and the paths and IP addresses excluded in its site settings.',
						'analytics-connector'
					) }
				</p>
			</div>
		</Card>
	);
}

/**
 * The checks, run against the saved settings.
 */
function SiteStatus( { saved } ) {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setStatus( null );
		setError( null );
		api( '/status' ).then( setStatus, setError );
	};
	useEffect( load, [ saved ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ errorMessage( error ) }
			</Notice>
		);
	}
	if ( ! status ) {
		return (
			<Loading
				label={ __( 'Asking the service…', 'analytics-connector' ) }
			/>
		);
	}
	const t = status.tracker;
	const k = status.key;
	const yes = ( on ) =>
		on ? (
			<Badge tone="ok">{ __( 'On', 'analytics-connector' ) }</Badge>
		) : (
			<Badge>{ __( 'Off', 'analytics-connector' ) }</Badge>
		);

	return (
		<>
			<Card
				title={ __( 'The tracker', 'analytics-connector' ) }
				subtitle={ __(
					'What the service sends every page, and what its settings say.',
					'analytics-connector'
				) }
				actions={
					<Button variant="secondary" size="small" onClick={ load }>
						{ __( 'Check again', 'analytics-connector' ) }
					</Button>
				}
			>
				<dl className="anc-tokens">
					<dt>{ __( 'Script', 'analytics-connector' ) }</dt>
					<dd>
						{ t.state === 'ok' && (
							<Badge tone="ok">
								{ sprintf(
									/* translators: %s: size in kB. */ __(
										'Served · %s kB',
										'analytics-connector'
									),
									formatNumber(
										Math.round( t.bytes / 102.4 ) / 10
									)
								) }
							</Badge>
						) }
						{ t.state === 'not_configured' && (
							<Badge>
								{ __(
									'Not set up: enter the service address and the public key',
									'analytics-connector'
								) }
							</Badge>
						) }
						{ t.state === 'unreachable' && (
							<Badge tone="error">
								{ __(
									'The service cannot be reached',
									'analytics-connector'
								) }
							</Badge>
						) }
						{ t.state === 'error' && (
							<Badge tone="error">
								{ sprintf(
									/* translators: %d: HTTP status. */ __(
										'Not served (HTTP %d): check the public key',
										'analytics-connector'
									),
									t.status
								) }
							</Badge>
						) }
						{ t.url && (
							<span className="anc-hint is-mono"> { t.url }</span>
						) }
					</dd>
					{ t.state === 'ok' && (
						<>
							<dt>{ __( 'Cookies', 'analytics-connector' ) }</dt>
							<dd>
								{ t.cookies
									? __(
											'After consent: returning visitors are recognised',
											'analytics-connector'
									  )
									: __(
											'None: every visit is anonymous',
											'analytics-connector'
									  ) }
							</dd>
							<dt>
								{ __(
									'Consent banner',
									'analytics-connector'
								) }
							</dt>
							<dd>
								{ t.banner ? (
									<Badge tone="ok">
										{ sprintf(
											/* translators: 1: version, 2: languages. */ __(
												'Published · version %1$d · %2$s',
												'analytics-connector'
											),
											t.version,
											t.languages
												.map( ( l ) => l.toUpperCase() )
												.join( ', ' )
										) }
									</Badge>
								) : t.cookies ? (
									<Badge tone="warn">
										{ __(
											'Not published: nobody is asked, so nobody gets cookies',
											'analytics-connector'
										) }
									</Badge>
								) : (
									<Badge>
										{ __(
											'Not needed without cookies',
											'analytics-connector'
										) }
									</Badge>
								) }
							</dd>
							<dt>
								{ __(
									'Links to other sites',
									'analytics-connector'
								) }
							</dt>
							<dd>{ yes( t.auto.outbound ) }</dd>
							<dt>
								{ __( 'Downloads', 'analytics-connector' ) }
							</dt>
							<dd>{ yes( t.auto.downloads ) }</dd>
							<dt>
								{ __( 'Forms sent', 'analytics-connector' ) }
							</dt>
							<dd>{ yes( t.auto.forms ) }</dd>
						</>
					) }
				</dl>
			</Card>
			<Card title={ __( 'The API key', 'analytics-connector' ) }>
				<dl className="anc-tokens">
					<dt>{ __( 'Reports', 'analytics-connector' ) }</dt>
					<dd>
						{ k.state === 'none' && (
							<Badge>
								{ __(
									'No key: the Overview stays empty',
									'analytics-connector'
								) }
							</Badge>
						) }
						{ k.state === 'ok' && (
							<Badge tone="ok">
								{ __(
									'The key reads this site’s reports',
									'analytics-connector'
								) }
							</Badge>
						) }
						{ k.state !== 'none' && k.state !== 'ok' && (
							<Badge tone="error">{ k.message }</Badge>
						) }
					</dd>
					{ k.hint && (
						<>
							<dt>{ __( 'Key', 'analytics-connector' ) }</dt>
							<dd>
								<code>{ k.hint }</code>{ ' ' }
								{ k.source === 'config'
									? __(
											'from wp-config.php',
											'analytics-connector'
									  )
									: __(
											'saved here, encrypted',
											'analytics-connector'
									  ) }
							</dd>
						</>
					) }
				</dl>
			</Card>
			{ status.service && (
				<p className="anc-hint">
					<a href={ status.service } target="_blank" rel="noreferrer">
						{ __(
							'Change the banner, cookies and automatic events on the service',
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
	);
}

function Consent() {
	return (
		<>
			<Card
				title={ __(
					'A link to change the choice',
					'analytics-connector'
				) }
				subtitle={ __(
					'Visitors must be able to change their mind about cookies. Any of these opens the banner again:',
					'analytics-connector'
				) }
			>
				<dl className="anc-tokens">
					<dt>{ __( 'Block', 'analytics-connector' ) }</dt>
					<dd>
						{ __(
							'“Consent settings link”, in the editor or a footer template.',
							'analytics-connector'
						) }
					</dd>
					<dt>{ __( 'Shortcode', 'analytics-connector' ) }</dt>
					<dd>
						<code>[analytics_consent_link]</code> ·{ ' ' }
						<code>
							{ '[analytics_consent_link label="Cookie"]' }
						</code>
					</dd>
					<dt>{ __( 'Menu', 'analytics-connector' ) }</dt>
					<dd>
						{ __( 'A custom link to', 'analytics-connector' ) }{ ' ' }
						<code>#analytics-consent</code>
					</dd>
					<dt>HTML</dt>
					<dd>
						<code>{ '<button data-analytics-consent>' }</code>
					</dd>
				</dl>
			</Card>
			<Card
				title={ __( 'Events and conversions', 'analytics-connector' ) }
			>
				<dl className="anc-tokens">
					<dt>{ __( 'A click', 'analytics-connector' ) }</dt>
					<dd>
						<code>
							{
								'<a data-analytics-event="call" data-analytics-prop-where="footer">'
							}
						</code>
					</dd>
					<dt>JavaScript</dt>
					<dd>
						<code>
							{ "analytics.track( 'signup', { plan: 'pro' } )" }
						</code>
					</dd>
					<dt>PHP</dt>
					<dd>
						<code>
							{
								"analytics_connector_track_conversion( 'booking', array( 'value' => array( 'amount_minor' => 5000, 'currency' => 'EUR' ) ) )"
							}
						</code>
						<span className="anc-hint">
							{ ' ' }
							—{ ' ' }
							{ __(
								'from the server, for what happens after the page: a payment, a confirmed booking. Needs an API key with “Write conversions”.',
								'analytics-connector'
							) }
						</span>
					</dd>
				</dl>
			</Card>
		</>
	);
}
