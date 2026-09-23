/**
 * The Analytics screens: one view per menu item, chosen by `data-view`. Data comes from the
 * `analytics-connector/v1/admin` API, which reads the service server-side.
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import Overview from './views/Overview';
import Settings from './views/Settings';
import './admin.scss';

const VIEWS = { overview: Overview, settings: Settings };

domReady( () => {
	const mount = document.getElementById( 'anc-app' );
	if ( mount ) {
		const View = VIEWS[ mount.dataset.view ] || Overview;
		createRoot( mount ).render( <View /> );
	}
} );
