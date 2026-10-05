/**
 * The installed-app concerns, next to the loader rather than inside it: the offline
 * worker's registration, the freshness line, the loading bar and the install button.
 * www/app.js talks to this file only through events — screen:loading, screen:loaded,
 * screen:shown, screen:morphed, screen:freshness — and nothing here fetches a screen.
 * Without JavaScript none of it exists, and the app is what it was.
 */
(function () {
	'use strict';

	var root = document.documentElement;
	var meta = function (name) {
		var el = document.querySelector('meta[name="' + name + '"]');
		return el ? el.content : '';
	};

	// --- the worker ------------------------------------------------------------
	// On every page, not only when the notification button is tapped: without a worker
	// nothing opens offline and Chrome does not offer to install the app. register() is
	// idempotent, so push.js registering the same script again changes nothing.
	if ('serviceWorker' in navigator && meta('event-base') !== '') {
		navigator.serviceWorker.register('sw.js', {scope: meta('event-base')}).catch(function () {
			// an insecure origin, a browser that refuses: the app works online as before
		});
	}

	// --- freshness -------------------------------------------------------------
	// "aktualizováno 14:05" from the screen's own data-fetched-at / data-stale. Read every
	// time the line is drawn, never remembered: a morph deferred while the reader was busy
	// updates the attributes only when the screen is shown again, and the line has to say
	// what they say then.
	function two(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function freshnessText(section) {
		var at = new Date(section.getAttribute('data-fetched-at') || '');
		if (isNaN(at.getTime())) {
			return '';
		}
		// HH:MM on the reader's own clock
		var time = two(at.getHours()) + ':' + two(at.getMinutes());
		if (navigator.onLine === false) {
			return 'offline · z ' + time;
		}

		return section.getAttribute('data-stale') === '1' ? 'naposledy načteno ' + time : 'aktualizováno ' + time;
	}

	function drawFreshness(scope) {
		var sections = scope && scope.matches && scope.matches('[data-screen]')
			? [scope]
			: document.querySelectorAll('[data-screen]');
		Array.prototype.forEach.call(sections, function (section) {
			var line = section.querySelector(':scope > [data-freshness]');
			if (!line) {
				return;
			}
			var text = freshnessText(section);
			if (line.textContent !== text) {
				line.textContent = text;
			}
			// unhidden only once it has something to say
			line.hidden = text === '';
		});
	}

	// --- loading ---------------------------------------------------------------
	// The bar at the tab bar's top edge, while the loader waits for a screen it has never
	// shown. A background revalidation dispatches neither event and shows nothing.
	document.addEventListener('screen:loading', function () {
		root.setAttribute('data-loading', '');
	});
	document.addEventListener('screen:loaded', function () {
		root.removeAttribute('data-loading');
	});

	// --- install ---------------------------------------------------------------
	// Chrome offers the install once the worker is in; the offer is kept for the button on
	// /profil rather than shown at once. iOS has no such event: its hint is push.js's.
	var deferredPrompt = null;

	function standalone() {
		return !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
	}

	function drawInstall() {
		document.querySelectorAll('[data-install]').forEach(function (el) {
			el.hidden = deferredPrompt === null || standalone();
		});
	}

	window.addEventListener('beforeinstallprompt', function (event) {
		event.preventDefault();
		deferredPrompt = event;
		drawInstall();
	});
	window.addEventListener('appinstalled', function () {
		deferredPrompt = null;
		drawInstall();
	});
	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('.install-btn') : null;
		if (!button || deferredPrompt === null) {
			return;
		}
		// a kept offer can be shown once, whatever the reader answers
		var offer = deferredPrompt;
		deferredPrompt = null;
		offer.prompt();
		drawInstall();
	});

	// --- when to draw ----------------------------------------------------------
	document.addEventListener('DOMContentLoaded', function () {
		drawFreshness(document);
		drawInstall();
	});
	// a morph writes the server's empty, hidden line and hidden button back
	['screen:shown', 'screen:morphed', 'screen:freshness'].forEach(function (name) {
		document.addEventListener(name, function (event) {
			drawFreshness(event.target);
			drawInstall();
		});
	});
	window.addEventListener('online', function () {
		drawFreshness(document);
	});
	window.addEventListener('offline', function () {
		drawFreshness(document);
	});
})();
