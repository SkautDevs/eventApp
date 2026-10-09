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

	// --- the map offline -------------------------------------------------------
	// Google's map cannot load without a network and is a blank box then. The note says so;
	// a map that never loaded is hidden behind it and loaded again once the signal is back.
	// One that did load stays as it is: it still shows what it fetched, and needs no note.
	//
	// load does not bubble, so one capture-phase listener sees every map iframe, including
	// one on a screen the loader inserts later. An iframe that fails during lie-fi gets
	// Chrome's error page, which fires load too and so counts as loaded — accepted: the
	// reader then sees the browser's own offline page in the box rather than a blank one.
	document.addEventListener('load', function (event) {
		var iframe = event.target;
		if (!iframe || iframe.tagName !== 'IFRAME' || !iframe.matches('[data-map] iframe')) {
			return;
		}
		if (navigator.onLine !== false) {
			iframe.closest('[data-map]').dataset.mapLoaded = '1';
		}
	}, true);

	function drawMap() {
		var offline = navigator.onLine === false;
		document.querySelectorAll('[data-map]').forEach(function (map) {
			var iframe = map.querySelector('iframe');
			var loaded = map.dataset.mapLoaded === '1';
			if (!offline && map.hidden && iframe) {
				iframe.src = iframe.src;
			}
			map.hidden = offline && !loaded;
		});
		// the note stands in for its map, the element right before it, only while that is hidden
		document.querySelectorAll('[data-map-offline]').forEach(function (note) {
			var map = note.previousElementSibling;
			note.hidden = !(map && map.hasAttribute('data-map') && map.hidden);
		});
	}

	document.addEventListener('DOMContentLoaded', drawMap);
	document.addEventListener('screen:shown', drawMap);
	document.addEventListener('screen:morphed', drawMap);
	window.addEventListener('online', drawMap);
	window.addEventListener('offline', drawMap);

	// --- the next programme (home screen) ----------------------------------------
	// Every one of the participant's programmes is rendered as a hidden card; the first
	// not yet over by the device clock is shown, so a homepage cached days ago (or read
	// offline) still names the right one. window.pgClock is the browser tests' clock,
	// as on the Program screen.
	var NEXT_TEXT = {next: 'Tvůj další program', running: 'Teď probíhá'};

	function drawNextUp() {
		var now = typeof window.pgClock === 'function' ? window.pgClock() : Date.now();
		document.querySelectorAll('[data-next-up]').forEach(function (box) {
			var shown = null;
			box.querySelectorAll('.next-card').forEach(function (card) {
				var show = shown === null && Date.parse(card.dataset.end) > now;
				if (show) {
					shown = card;
				}
				if (card.hidden === show) {
					card.hidden = !show;
				}
			});
			if (box.hidden !== (shown === null)) {
				box.hidden = shown === null;
			}
			if (shown) {
				var kicker = shown.querySelector('[data-next-kicker]');
				var text = Date.parse(shown.dataset.start) <= now ? NEXT_TEXT.running : NEXT_TEXT.next;
				if (kicker && kicker.textContent !== text) {
					kicker.textContent = text;
				}
			}
		});
	}

	document.addEventListener('DOMContentLoaded', drawNextUp);
	document.addEventListener('screen:shown', drawNextUp);
	// a morph writes the server's pick back
	document.addEventListener('screen:morphed', drawNextUp);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			drawNextUp();
		}
	});
	document.addEventListener('pg:tick', drawNextUp);
	setInterval(drawNextUp, 60000);

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
	// the homepage and /profil rather than shown at once. iOS has no such event and no way
	// to install from a page, so there the same place carries how to do it by hand.
	var deferredPrompt = null;

	function standalone() {
		return navigator.standalone === true
			|| !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
	}

	// iPadOS reports itself as a Mac; the touch points give it away (as in push.js)
	function ios() {
		return /iPhone|iPad|iPod/.test(navigator.userAgent)
			|| (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	}

	function drawInstall() {
		document.querySelectorAll('[data-install]').forEach(function (el) {
			el.hidden = deferredPrompt === null || standalone();
		});
		document.querySelectorAll('[data-install-ios]').forEach(function (el) {
			el.hidden = !ios() || standalone();
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
