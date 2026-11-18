/**
 * The loading bar at the tab bar's top edge, while the loader waits for a screen it has
 * never shown. A background revalidation announces neither event and shows nothing.
 */

import {onScreenLoaded, onScreenLoading} from '../events.js';

const root = document.documentElement;

onScreenLoading(() => {
	root.setAttribute('data-loading', '');
});
onScreenLoaded(() => {
	root.removeAttribute('data-loading');
});
