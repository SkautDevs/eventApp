/**
 * Profil: the pairing poll. (The two-tap confirmation of its logout is www/shell.js's,
 * shared with the unregister in a programme's sheet.)
 *
 * The poll. While the pairing code is on a visible screen ([data-pairing-poll]), the page
 * asks eventApp every 5 s whether KissJ has answered; eventApp asks kissj with the device
 * code kept in the server-side session. "approved", "denied" and "expired" end it: the
 * page reloads Profil, which then shows the reader paired, or the line that says why not.
 * "pending" and "slowDown" mean ask again, and so does a request that failed — eventApp
 * answers its own failures as "pending" too. Leaving the screen (or the page) stops the
 * polling; coming back resumes it with the same code, which lives for an hour.
 */
(function () {
	'use strict';

	var INTERVAL = 5000;
	var meta = document.querySelector('meta[name="event-base"]');
	var base = meta ? meta.getAttribute('content') : '/';
	var timer = null;
	var asking = false;
	var leaving = false;

	/** The code on a screen the reader can see, or null. */
	function visibleCode() {
		var codes = document.querySelectorAll('[data-pairing-poll]');
		for (var i = 0; i < codes.length; i++) {
			var screen = codes[i].closest('[data-screen]');
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
			.then(function (response) {
				return response.ok ? response.json() : null;
			})
			.then(function (body) {
				asking = false;
				var status = body && body.status;
				if (status === 'approved' || status === 'denied' || status === 'expired') {
					// the server renders the outcome: a paired Profil, or the line that says why not
					leaving = true;
					stop();
					location.assign(base + 'profil');
					return;
				}
				schedule(INTERVAL);
			}, function () {
				// no network, or not JSON: the pairing is still there, so keep asking
				asking = false;
				schedule(INTERVAL);
			});
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
	document.addEventListener('screen:shown', start);
	document.addEventListener('screen:hidden', stop);
	window.addEventListener('pagehide', stop);
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			start();
		}
	});
	if (document.readyState !== 'loading') {
		start();
	}
})();
