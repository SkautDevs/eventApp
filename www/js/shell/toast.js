/**
 * The toast: one at a time, under the app bar, gone after 6 s or on its close button. The
 * server renders one into a screen after a login or a logout (templates/_toast.twig); this
 * builds the same element for the shell's own, and times either kind.
 */

import {onScreenFreshness, onScreenMorphed, onScreenShown} from '../events.js';
import {visibleScreen} from '../ui/screen.js';

const TOAST_MS = 6000;

// A toast whose close button has the focus takes it along when it goes, and a keyboard or
// screen reader user would be left on <body>: the focus moves to the visible screen,
// the same target the loader focuses after a swap — or, with the programme sheet open
// over it, to the sheet's card, since everything else is inert under the sheet's trap.
function removeToast(toast) {
	if (!toast.parentNode) {
		return;
	}
	const focused = toast.contains(document.activeElement);
	toast.parentNode.removeChild(toast);
	const section = focused ? visibleScreen() : null;
	if (!section) {
		return;
	}
	const dialog = section.querySelector('.sheet.is-open [role="dialog"]');
	(dialog || section).focus({preventScroll: true});
}

function armToast(toast) {
	if (toast.hasAttribute('data-toast-armed')) {
		return;
	}
	toast.setAttribute('data-toast-armed', '');
	setTimeout(() => {
		removeToast(toast);
	}, TOAST_MS);
}

// a server toast starts its time when its screen is on display, not while it waits hidden
function armToasts() {
	const section = visibleScreen();
	if (section) {
		section.querySelectorAll('[data-toast]').forEach(armToast);
	}
}

/** Shows the shell's own toast on the visible screen, in place of any other: a title and an optional second line, built from text. */
export function showToast(title, line) {
	const section = visibleScreen();
	if (!section) {
		return;
	}
	document.querySelectorAll('[data-toast]').forEach(removeToast);
	const toast = document.createElement('div');
	toast.className = 'toast';
	toast.setAttribute('role', 'status');
	toast.setAttribute('data-toast', '');
	toast.setAttribute('data-key', 'toast');
	const text = document.createElement('p');
	const strong = document.createElement('strong');
	strong.textContent = title;
	text.appendChild(strong);
	if (line) {
		const second = document.createElement('span');
		second.textContent = line;
		text.appendChild(second);
	}
	const close = document.createElement('button');
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

document.addEventListener('click', event => {
	const close = event.target.closest ? event.target.closest('[data-toast-close]') : null;
	const toast = close ? close.closest('[data-toast]') : null;
	if (toast) {
		removeToast(toast);
	}
});

// A write tapped with no signal: the worker answered it with a redirect back here plus
// #offline (www/sw.js), and the hash is said once and then taken away, so a reload or a
// shared link does not say it again. The loader's state object is passed through.
document.addEventListener('DOMContentLoaded', () => {
	if (location.hash === '#offline') {
		showToast('Akci není možné provést, protože jsi offline.', 'Zkus to znovu až najdeš místo se signálem.');
		history.replaceState(history.state, '', location.pathname + location.search);
	}
});

// a morph writes the server's toast back
document.addEventListener('DOMContentLoaded', armToasts);
onScreenShown(armToasts);
onScreenMorphed(armToasts);
onScreenFreshness(armToasts);
