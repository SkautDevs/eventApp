/**
 * The warm-up: every other screen is fetched once the first has settled, one after the
 * other and inserted hidden, so the first tap on a tab is as instant as every later one.
 * It stops at the first failure (offline, an error page: the tap's own fetch will say
 * what is wrong), and does not run at all when the reader asked to save data.
 */

import {missingScreens, preload} from './loader.js';

function warm() {
	// appWarm = false is the browser tests' switch for a cold screen
	if ((navigator.connection && navigator.connection.saveData) || window.appWarm === false) {
		return;
	}
	warmIcons();
	const queue = missingScreens();
	(function next() {
		const path = queue.shift();
		if (path === undefined) {
			return;
		}
		if (!missingScreens().includes(path)) {
			next();

			return;
		}
		preload(path).then(arrived => {
			if (arrived) {
				next();
			}
		});
	})();
}

/**
 * The browser reads a font in only when something visible first uses it, and a warmed
 * screen is hidden — so the quick links' brand icons would still blink in on its first show.
 * The icon fonts are loaded up front instead (the worker already holds the files; the
 * event's own text fonts are in use from the first page anyway).
 */
function warmIcons() {
	if (!document.fonts || typeof document.fonts.forEach !== 'function') {
		return;
	}
	document.fonts.forEach(face => {
		if (face.status === 'unloaded' && face.family.indexOf('Font Awesome') >= 0) {
			face.load().catch(() => {});
		}
	});
}

function schedule() {
	const idle =
		window.requestIdleCallback ||
		function (run) {
			return setTimeout(run, 1000);
		};
	idle(warm, {timeout: 3000});
}

/** Warms the other screens once the page has loaded, when the browser is idle. */
export function scheduleWarm() {
	if (document.readyState === 'complete') {
		schedule();
	} else {
		window.addEventListener('load', schedule);
	}
}
