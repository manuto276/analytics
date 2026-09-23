import { make } from './banner';

/**
 * Entry of dist/banner.js. The server concatenates it before the core (dist/tracker.js) only for
 * sites with the cookie level on; the core picks the factory up from this private global.
 */
(window as unknown as Record<string, unknown>).__an_b = make;
