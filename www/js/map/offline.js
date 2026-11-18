/**
 * The map offline. Google's map cannot load without a network and is a blank box then. The
 * note says so; a map that never loaded is hidden behind it and loaded again once the
 * signal is back. One that did load stays as it is: it still shows what it fetched, and
 * needs no note.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';

// load does not bubble, so one capture-phase listener sees every map iframe, including
// one on a screen the loader inserts later. An iframe that fails during lie-fi gets
// Chrome's error page, which fires load too and so counts as loaded — accepted: the
// reader then sees the browser's own offline page in the box rather than a blank one.
function markLoaded(event) {
	const iframe = event.target;
	if (!iframe || iframe.tagName !== 'IFRAME' || !iframe.matches('[data-map] iframe')) {
		return;
	}
	if (navigator.onLine !== false) {
		iframe.closest('[data-map]').dataset.mapLoaded = '1';
	}
}

function drawMap() {
	const offline = navigator.onLine === false;
	document.querySelectorAll('[data-map]').forEach(map => {
		const iframe = map.querySelector('iframe');
		const loaded = map.dataset.mapLoaded === '1';
		if (!offline && map.hidden && iframe) {
			// biome-ignore lint/correctness/noSelfAssign: assigning src reloads the iframe
			iframe.src = iframe.src;
		}
		map.hidden = offline && !loaded;
	});
	// the note stands in for its map, the element right before it, only while that is hidden
	document.querySelectorAll('[data-map-offline]').forEach(note => {
		const map = note.previousElementSibling;
		note.hidden = !(map && map.hasAttribute('data-map') && map.hidden);
	});
}

document.addEventListener('load', markLoaded, true);
document.addEventListener('DOMContentLoaded', drawMap);
onScreenShown(drawMap);
onScreenMorphed(drawMap);
window.addEventListener('online', drawMap);
window.addEventListener('offline', drawMap);
