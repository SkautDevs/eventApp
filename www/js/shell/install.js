/**
 * The install. Chrome offers it once the worker is in; the offer is kept for the button on
 * the homepage and /profil rather than shown at once. iOS has no such event and no way to
 * install from a page, so there the same place carries how to do it by hand. In an
 * installed app Domů's install card has nothing left to offer; Profil's keeps its
 * notifications button and only its install half goes.
 */

import {onScreenFreshness, onScreenMorphed, onScreenShown} from '../events.js';

let deferredPrompt = null;

/** Whether the page runs as the installed app: iOS's own flag, or the display mode elsewhere. */
export function standalone(nav = navigator, media = window.matchMedia ? query => window.matchMedia(query) : null) {
	return nav.standalone === true || !!(media && media('(display-mode: standalone)').matches);
}

/** iPadOS reports itself as a Mac; the touch points give it away (as in push/index.js). */
export function ios(nav = navigator) {
	return /iPhone|iPad|iPod/.test(nav.userAgent) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
}

function drawInstallCard() {
	document.querySelectorAll('[data-install-card="home"]').forEach(card => {
		card.hidden = standalone();
	});
}

function drawInstall() {
	document.querySelectorAll('[data-install]').forEach(el => {
		el.hidden = deferredPrompt === null || standalone();
	});
	document.querySelectorAll('[data-install-ios]').forEach(el => {
		el.hidden = !ios() || standalone();
	});
	drawInstallCard();
}

window.addEventListener('beforeinstallprompt', event => {
	event.preventDefault();
	deferredPrompt = event;
	drawInstall();
});
window.addEventListener('appinstalled', () => {
	deferredPrompt = null;
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
	offer.prompt();
	drawInstall();
});

// a morph writes the server's hidden button back
document.addEventListener('DOMContentLoaded', drawInstall);
onScreenShown(drawInstall);
onScreenMorphed(drawInstall);
onScreenFreshness(drawInstall);
