/**
 * Profil: the pairing poll. (The two-tap confirmation of its logout is shell/confirm.js's,
 * shared with the unregister in a programme's sheet.)
 *
 * While the pairing code is on a visible screen ([data-pairing-poll]), the page asks
 * eventApp every 5 s whether KissJ has answered; eventApp asks kissj with the device code
 * kept in the server-side session. "approved" and "expired" end it: the page reloads
 * Profil, which then shows the reader paired, or the line that says why not. "pending"
 * and "slowDown" mean ask again, and so does a request that failed — eventApp answers its
 * own failures as "pending" too. Leaving the screen (or the page) stops the polling;
 * coming back resumes it with the same code, which lives for an hour.
 */

import {onScreenHidden, onScreenShown} from '../events.js';

const INTERVAL = 5000;
const meta = document.querySelector('meta[name="event-base"]');
const base = meta ? meta.getAttribute('content') : '/';
let timer = null;
let asking = false;
let leaving = false;

/** The code on a screen the reader can see, or null. */
function visibleCode() {
	const codes = document.querySelectorAll('[data-pairing-poll]');
	for (let i = 0; i < codes.length; i++) {
		const screen = codes[i].closest('[data-screen]');
		if (!screen || !screen.hidden) {
			return codes[i];
		}
	}
	return null;
}

function stop() {
	if (timer !== null) {
		clearTimeout(timer);
		timer = null;
	}
}

function schedule(delay) {
	stop();
	timer = setTimeout(ask, delay);
}

function ask() {
	timer = null;
	if (leaving || !window.fetch || !visibleCode()) {
		return;
	}
	if (asking) {
		schedule(INTERVAL);
		return;
	}
	asking = true;
	fetch(base + 'profil/propojit/stav', {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}})
		.then(response => (response.ok ? response.json() : null))
		.then(
			body => {
				asking = false;
				const status = body && body.status;
				if (status === 'approved' || status === 'expired') {
					// the server renders the outcome: a paired Profil, or the line that says why not
					leaving = true;
					stop();
					location.assign(base + 'profil');
					return;
				}
				schedule(INTERVAL);
			},
			() => {
				// no network, or not JSON: the pairing is still there, so keep asking
				asking = false;
				schedule(INTERVAL);
			},
		);
}

function start() {
	leaving = false;
	if (visibleCode()) {
		schedule(0);
	} else {
		stop();
	}
}

document.addEventListener('DOMContentLoaded', start);
onScreenShown(start);
onScreenHidden(stop);
window.addEventListener('pagehide', stop);
window.addEventListener('pageshow', event => {
	if (event.persisted) {
		start();
	}
});
if (document.readyState !== 'loading') {
	start();
}
