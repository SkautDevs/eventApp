/**
 * The installed-app concerns, next to the loader rather than inside it: the offline
 * worker's registration, the network banner, the toast, the two-tap confirmations, the
 * loading bar, the install card and the home screen's clock-driven parts.
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

	// --- the network banner --------------------------------------------------------
	// One banner above the tab bar, for the visible screen: offline, how old its data is;
	// online with an old copy the server could not refresh (data-stale), the same; otherwise
	// nothing. Read off the screen's own data-fetched-at / data-stale every time it is drawn,
	// never remembered: a morph deferred while the reader was busy updates the attributes
	// only when the screen is shown again, and the banner has to say what they say then.
	//
	// What it says is spoken through its own live region (data-netstate-live), without the
	// age: "před N min" moves on every minute, and a live region would say the whole sentence
	// again each time. The banner itself is hidden from assistive tech, so nothing is read
	// twice.
	function two(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function visibleScreen() {
		return document.querySelector('main > [data-screen]:not([hidden])');
	}

	// "(právě teď)", "(před 25 min)", "(před 3 h)", or the day itself once it is a day old
	function age(at, now) {
		var minutes = Math.max(0, Math.floor((now - at.getTime()) / 60000));
		if (minutes < 1) {
			return '(právě teď)';
		}
		if (minutes < 60) {
			return '(před ' + minutes + ' min)';
		}
		if (minutes < 24 * 60) {
			return '(před ' + Math.floor(minutes / 60) + ' h)';
		}

		return 'z ' + at.getDate() + '. ' + (at.getMonth() + 1) + '.';
	}

	// spoken: the sentence for the live region, which keeps the day but not the ticking age
	function netstateText(section, spoken) {
		var at = new Date(section.getAttribute('data-fetched-at') || '');
		if (isNaN(at.getTime())) {
			return '';
		}
		// HH:MM on the reader's own clock; the age from homeNow(), the tests' clock when pinned
		var ago = age(at, homeNow());
		var when = two(at.getHours()) + ':' + two(at.getMinutes()) + (spoken && ago.charAt(0) === '(' ? '' : ' ' + ago);
		// the date band already ends in its own full stop
		var end = when.charAt(when.length - 1) === '.' ? '' : '.';
		if (navigator.onLine === false) {
			return 'Jsi offline. Data byla naposledy aktualizována v ' + when + end;
		}
		if (section.getAttribute('data-stale') === '1') {
			return 'Nepodařilo se načíst aktuální data. Zobrazuji stav z ' + when + end;
		}

		return '';
	}

	// The banner's height as --netstate-height on the root (0px while it is hidden): the
	// docked view switcher stands on it, and every screen's bottom padding and one-screenful
	// height make room for it (www/style.css), so it covers nothing. Measured on every draw,
	// and again whenever its box changes by itself (a font arriving, a rotated phone
	// wrapping it onto another number of lines).
	var netstateHeight = -1;
	var netstateWatched = false;

	function watchNetstate(banner) {
		if (netstateWatched) {
			return;
		}
		netstateWatched = true;
		if (typeof ResizeObserver === 'function') {
			new ResizeObserver(function () {
				publishNetstateHeight(banner);
			}).observe(banner);
		} else {
			window.addEventListener('resize', drawNetstate);
		}
	}

	function publishNetstateHeight(banner) {
		var height = banner.hidden ? 0 : Math.ceil(banner.getBoundingClientRect().height);
		if (height !== netstateHeight) {
			netstateHeight = height;
			root.style.setProperty('--netstate-height', height + 'px');
		}
	}

	function drawNetstate() {
		var banner = document.querySelector('[data-netstate]');
		if (!banner) {
			return;
		}
		var section = visibleScreen();
		var text = section ? netstateText(section, false) : '';
		if (banner.textContent !== text) {
			banner.textContent = text;
		}
		// shown only once it has something to say
		if (banner.hidden !== (text === '')) {
			banner.hidden = text === '';
		}
		// written only when the state (or the time it names) changes, so it speaks only then
		var live = document.querySelector('[data-netstate-live]');
		var spoken = section ? netstateText(section, true) : '';
		if (live && live.textContent !== spoken) {
			live.textContent = spoken;
		}
		publishNetstateHeight(banner);
		watchNetstate(banner);
	}

	// --- the toast -------------------------------------------------------------------
	// One at a time, under the app bar, gone after 6 s or on its close button. The server
	// renders one into a screen after a login or a logout (templates/_toast.twig); this
	// builds the same element for the shell's own, and times either kind.
	var TOAST_MS = 6000;

	// A toast whose close button has the focus takes it along when it goes, and a keyboard or
	// screen reader user would be left on <body>: the focus moves to the visible screen,
	// the same target the loader focuses after a swap — or, with the programme sheet open
	// over it, to the sheet's card, since everything else is inert under the sheet's trap.
	function removeToast(toast) {
		if (!toast.parentNode) {
			return;
		}
		var focused = toast.contains(document.activeElement);
		toast.parentNode.removeChild(toast);
		var section = focused ? visibleScreen() : null;
		if (!section) {
			return;
		}
		var dialog = section.querySelector('.sheet.is-open [role="dialog"]');
		(dialog || section).focus({preventScroll: true});
	}

	function armToast(toast) {
		if (toast.hasAttribute('data-toast-armed')) {
			return;
		}
		toast.setAttribute('data-toast-armed', '');
		setTimeout(function () {
			removeToast(toast);
		}, TOAST_MS);
	}

	// a server toast starts its time when its screen is on display, not while it waits hidden
	function armToasts() {
		var section = visibleScreen();
		if (section) {
			section.querySelectorAll('[data-toast]').forEach(armToast);
		}
	}

	function showToast(title, line) {
		var section = visibleScreen();
		if (!section) {
			return;
		}
		document.querySelectorAll('[data-toast]').forEach(removeToast);
		var toast = document.createElement('div');
		toast.className = 'toast';
		toast.setAttribute('role', 'status');
		toast.setAttribute('data-toast', '');
		toast.setAttribute('data-key', 'toast');
		var text = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = title;
		text.appendChild(strong);
		if (line) {
			var second = document.createElement('span');
			second.textContent = line;
			text.appendChild(second);
		}
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'toast-close';
		close.setAttribute('data-toast-close', '');
		close.setAttribute('aria-label', 'Zavřít');
		close.textContent = '✕';
		toast.appendChild(text);
		toast.appendChild(close);
		section.insertBefore(toast, section.firstChild);
		armToast(toast);
	}

	document.addEventListener('click', function (event) {
		var close = event.target.closest ? event.target.closest('[data-toast-close]') : null;
		var toast = close ? close.closest('[data-toast]') : null;
		if (toast) {
			removeToast(toast);
		}
	});

	// A write tapped with no signal: the worker answered it with a redirect back here plus
	// #offline (www/sw.js), and the hash is said once and then taken away, so a reload or a
	// shared link does not say it again.
	document.addEventListener('DOMContentLoaded', function () {
		if (location.hash === '#offline') {
			showToast('Akci není možné provést, protože jsi offline.', 'Zkus to znovu až najdeš místo se signálem.');
			history.replaceState(history.state, '', location.pathname + location.search);
		}
	});

	// --- the two-tap confirmations ----------------------------------------------
	// A button carrying data-confirm (the logout on Profil, the unregister in a programme's
	// sheet) does not submit on the first tap: it swaps its label for the question and the
	// second tap submits. A page shown again from the back/forward cache, a screen left, or
	// a programme's sheet closed (www/programs.js's sheet:closed) starts over at the first tap.
	function resetConfirms(scope) {
		scope.querySelectorAll('[data-confirm][data-confirming]').forEach(function (button) {
			button.textContent = button.getAttribute('data-label');
			button.removeAttribute('data-confirming');
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-confirm]') : null;
		if (!button || button.hasAttribute('data-confirming')) {
			// not one of ours, or the second tap: the form submits as it always would
			return;
		}
		event.preventDefault();
		button.setAttribute('data-label', button.textContent);
		button.setAttribute('data-confirming', '');
		button.textContent = button.getAttribute('data-confirm');
	});
	document.addEventListener('screen:hidden', function (event) {
		resetConfirms(event.target);
	});
	document.addEventListener('sheet:closed', function (event) {
		resetConfirms(event.target);
	});
	window.addEventListener('pageshow', function () {
		resetConfirms(document);
	});

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

	// --- the home screen and the relative times -------------------------------------
	// Domů is rendered for the day it was fetched on, and read on others: a copy cached
	// days ago or read offline. Its clock-driven parts — the next programmes, the countdown,
	// the date heading — and every notification's relative time are therefore drawn here
	// from the device clock. window.pgClock is the browser tests' clock, as on the Program
	// screen, and pg:tick their way to make it move.
	function homeNow() {
		return typeof window.pgClock === 'function' ? window.pgClock() : Date.now();
	}

	// Můj program: every one of the participant's programmes is a hidden card carrying its
	// instants; the running one and the next two are shown, each with its lead line. The
	// times are the server's (data-time, data-until: Prague wall-clock), so a phone in
	// another zone still reads the event's own.
	var NEXT_SHOWN = 3;
	var HOUR = 3600000;

	function nextLead(card, now) {
		var place = card.dataset.location ? ' · ' + card.dataset.location : '';
		var start = Date.parse(card.dataset.start);
		if (start <= now) {
			return 'Právě · ' + card.dataset.until + place;
		}
		if (start - now <= HOUR) {
			return 'Za ' + Math.ceil((start - now) / 60000) + ' min · ' + card.dataset.time + place;
		}

		return card.dataset.time + place;
	}

	function drawNextUp(now) {
		document.querySelectorAll('[data-next-up]').forEach(function (box) {
			var shown = 0;
			box.querySelectorAll('.next-card').forEach(function (card) {
				var show = shown < NEXT_SHOWN && Date.parse(card.dataset.end) > now;
				if (show) {
					shown++;
					var lead = card.querySelector('[data-next-lead]');
					var text = nextLead(card, now);
					if (lead && lead.textContent !== text) {
						lead.textContent = text;
					}
				}
				if (card.hidden === show) {
					card.hidden = !show;
				}
			});
			if (box.hidden !== (shown === 0)) {
				box.hidden = shown === 0;
			}
		});
	}

	// The countdown before the event: whole days from today to the start, both read on the
	// Prague calendar, so it turns at Prague's midnight on any phone. Gone once the start
	// has come.
	var pragueDate = typeof Intl === 'object' && Intl.DateTimeFormat
		? new Intl.DateTimeFormat('en-GB', {timeZone: 'Europe/Prague', year: 'numeric', month: '2-digit', day: '2-digit'})
		: null;

	// "YYYY-MM-DD" of the Prague day an instant falls on, from the parts rather than a
	// locale's printed order
	function pragueDay(ms) {
		if (pragueDate === null || !pragueDate.formatToParts) {
			return new Date(ms).toISOString().slice(0, 10);
		}
		var parts = {};
		pragueDate.formatToParts(new Date(ms)).forEach(function (part) {
			parts[part.type] = part.value;
		});

		return parts.year + '-' + parts.month + '-' + parts.day;
	}

	// The Prague wall clock of an instant, as two-digit strings (year in four): what the
	// server's NewsItems::relative() and HomeModel::todayLabel() read, on any phone's zone.
	var pragueClock = typeof Intl === 'object' && Intl.DateTimeFormat
		? new Intl.DateTimeFormat('en-GB', {timeZone: 'Europe/Prague', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'})
		: null;

	function pragueParts(ms) {
		if (pragueClock === null || !pragueClock.formatToParts) {
			var local = new Date(ms);
			return {year: String(local.getFullYear()), month: two(local.getMonth() + 1), day: two(local.getDate()), hour: two(local.getHours()), minute: two(local.getMinutes())};
		}
		var parts = {};
		pragueClock.formatToParts(new Date(ms)).forEach(function (part) {
			parts[part.type] = part.value;
		});

		return parts;
	}

	// The day before a Prague date, as "YYYY-MM-DD": calendar arithmetic on a UTC midnight,
	// so no DST change can move it.
	function dayBefore(parts) {
		return new Date(Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day)) - 86400000).toISOString().slice(0, 10);
	}

	// When a notification was sent, as NewsItems::relative() says it on the server, with the
	// same bands in the same order: under a minute "právě teď"; under an hour "před N min",
	// even across midnight; the same Prague day "před N h"; the day before "včera HH:MM";
	// a date before that. Change both together.
	function relativeTime(at, now) {
		var seconds = Math.floor((now - at) / 1000);
		if (seconds < 60) {
			return 'právě teď';
		}
		if (seconds < 3600) {
			return 'před ' + Math.floor(seconds / 60) + ' min';
		}
		var sent = pragueParts(at);
		var today = pragueParts(now);
		var sentDay = sent.year + '-' + sent.month + '-' + sent.day;
		if (sentDay === today.year + '-' + today.month + '-' + today.day) {
			return 'před ' + Math.floor(seconds / 3600) + ' h';
		}
		var time = sent.hour + ':' + sent.minute;
		if (sentDay === dayBefore(today)) {
			return 'včera ' + time;
		}

		return Number(sent.day) + '. ' + Number(sent.month) + '. ' + time;
	}

	// Every relative time the server wrote with its instant: the notifications on Informace
	// and Domů, and the messages in a programme's sheet. Rendered for the moment they were
	// fetched, they would keep saying "právě teď" in a copy read an hour later.
	function drawSentAt(now) {
		document.querySelectorAll('[data-sent-at]').forEach(function (el) {
			var at = Date.parse(el.getAttribute('data-sent-at'));
			if (isNaN(at)) {
				return;
			}
			var text = relativeTime(at, now);
			if (el.textContent !== text) {
				el.textContent = text;
			}
		});
	}

	// Domů's date heading, "Pátek 21. 5." on the Prague calendar, as HomeModel::todayLabel()
	var WEEKDAYS = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

	function drawHomeDate(now) {
		var today = pragueParts(now);
		var weekday = new Date(Date.UTC(Number(today.year), Number(today.month) - 1, Number(today.day))).getUTCDay();
		var text = WEEKDAYS[weekday] + ' ' + Number(today.day) + '. ' + Number(today.month) + '.';
		document.querySelectorAll('[data-home-date]').forEach(function (heading) {
			if (heading.textContent !== text) {
				heading.textContent = text;
			}
		});
	}

	function czechDays(n) {
		var forms = ['den', 'dny', 'dní'];

		return n === 1 ? forms[0] : (n >= 2 && n <= 4 ? forms[1] : forms[2]);
	}

	function drawCountdown(now) {
		var today = Date.parse(pragueDay(now) + 'T00:00:00Z');
		document.querySelectorAll('[data-countdown]').forEach(function (card) {
			var days = Math.round((Date.parse(card.dataset.start + 'T00:00:00Z') - today) / 86400000);
			if (isNaN(days) || days <= 0) {
				card.hidden = true;
				return;
			}
			var count = card.querySelector('[data-countdown-days]');
			var unit = card.querySelector('[data-countdown-unit]');
			if (count && count.textContent !== String(days)) {
				count.textContent = String(days);
			}
			if (unit && unit.textContent !== czechDays(days)) {
				unit.textContent = czechDays(days);
			}
			if (card.hidden) {
				card.hidden = false;
			}
		});
	}

	// In an installed app Domů's install card has nothing left to offer; Profil's keeps its
	// notifications button and only its install half goes (drawInstall).
	function drawInstallCard() {
		document.querySelectorAll('[data-install-card="home"]').forEach(function (card) {
			card.hidden = standalone();
		});
	}

	function drawHome() {
		var now = homeNow();
		drawNextUp(now);
		drawCountdown(now);
		drawHomeDate(now);
		drawSentAt(now);
	}

	document.addEventListener('DOMContentLoaded', drawHome);
	document.addEventListener('screen:shown', drawHome);
	// a morph writes the server's hidden cards and its count back
	document.addEventListener('screen:morphed', drawHome);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			drawHome();
		}
	});
	document.addEventListener('pg:tick', drawHome);
	setInterval(drawHome, 60000);

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
		drawInstallCard();
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
		drawNetstate();
		armToasts();
		drawInstall();
	});
	// a morph writes the server's hidden button back, and the banner follows the screen
	['screen:shown', 'screen:morphed', 'screen:freshness'].forEach(function (name) {
		document.addEventListener(name, function () {
			drawNetstate();
			armToasts();
			drawInstall();
		});
	});
	window.addEventListener('online', drawNetstate);
	window.addEventListener('offline', drawNetstate);
	// "před N min" moves on by itself
	setInterval(drawNetstate, 60000);
	document.addEventListener('pg:tick', drawNetstate);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			drawNetstate();
		}
	});
})();
