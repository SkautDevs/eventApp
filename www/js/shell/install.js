/**
 * The install card, on Domů and Profil. It is rendered hidden with its four paragraphs, and
 * one case decides what shows (installCase(), first match wins):
 *
 *  - an installed app: nothing, the card stays hidden;
 *  - a kept offer: Chrome's own (beforeinstallprompt, once the worker is in), shown only from
 *    the button, since its prompt needs a tap; it wins over every step by hand, so an offer
 *    that arrives late replaces the Android paragraph;
 *  - an in-app browser (Facebook, Instagram, Messenger, Line, LinkedIn) on a phone or tablet,
 *    which cannot install at all: open the page in the browser;
 *  - iOS, which has no offer: Sdílet, then Přidat na plochu;
 *  - Android without an offer: the browser's own menu;
 *  - anything else (a computer): nothing.
 *
 * Once the kept offer has been shown, or the app is installed (appinstalled), `done` hides
 * the card until the next document.
 */

import {onScreenFreshness, onScreenMorphed, onScreenShown} from '../events.js';

let deferredPrompt = null;
let done = false;

/** Whether the page runs as the installed app: iOS's own flag, or the display mode elsewhere. */
export function standalone(nav = navigator, media = window.matchMedia ? query => window.matchMedia(query) : null) {
	return nav.standalone === true || !!(media && media('(display-mode: standalone)').matches);
}

/** iPadOS reports itself as a Mac; the touch points give it away (as in push/index.js). */
export function ios(nav = navigator) {
	return /iPhone|iPad|iPod/.test(nav.userAgent) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
}

export function android(nav = navigator) {
	return /Android/.test(nav.userAgent);
}

/** The in-app browsers of the social apps, by the markers they add to the user agent. */
export function inApp(nav = navigator) {
	return /FBAN|FBAV|FB_IAB|Instagram|Messenger|Line\/|LinkedInApp/.test(nav.userAgent);
}

/** A phone or a tablet: an in-app marker counts only there. */
export function mobile(nav = navigator) {
	return ios(nav) || /Android|Mobi/.test(nav.userAgent);
}

/** Which paragraph the card shows: 'offer', 'inApp', 'ios' or 'android', or 'none' (the card hidden). */
export function installCase({standalone, offer, ios, android, inApp, mobile}) {
	if (standalone) {
		return 'none';
	}
	if (offer) {
		return 'offer';
	}
	if (inApp && mobile) {
		return 'inApp';
	}
	if (ios) {
		return 'ios';
	}
	if (android) {
		return 'android';
	}
	return 'none';
}

function drawInstall() {
	const choice = done
		? 'none'
		: installCase({
				standalone: standalone(),
				offer: deferredPrompt !== null,
				ios: ios(),
				android: android(),
				inApp: inApp(),
				mobile: mobile(),
			});
	document.querySelectorAll('[data-install-card]').forEach(card => {
		card.hidden = choice === 'none';
	});
	document.querySelectorAll('[data-install]').forEach(el => {
		el.hidden = el.dataset.install !== choice;
	});
}

window.addEventListener('beforeinstallprompt', event => {
	event.preventDefault();
	deferredPrompt = event;
	drawInstall();
});
window.addEventListener('appinstalled', () => {
	deferredPrompt = null;
	done = true;
	drawInstall();
});
document.addEventListener('click', event => {
	const button = event.target.closest ? event.target.closest('.install-btn') : null;
	if (!button || deferredPrompt === null) {
		return;
	}
	// a kept offer can be shown once, whatever the reader answers
	const offer = deferredPrompt;
	deferredPrompt = null;
	done = true;
	offer.prompt();
	drawInstall();
});

// a screen inserted or morphed carries the server's hidden card again
document.addEventListener('DOMContentLoaded', drawInstall);
onScreenShown(drawInstall);
onScreenMorphed(drawInstall);
onScreenFreshness(drawInstall);
