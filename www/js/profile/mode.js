/**
 * Profil's colour-mode control: three radios, Světlý / Tmavý / Podle systému. The mode
 * itself is already on the root: the inline script in <head> set it before first paint
 * from the same storage key. This only reflects the stored choice in the radios and
 * handles a change. The key is global rather than per event, because dark is a property
 * of the reader's eyes, not of which event they are looking at; "system" removes it, so
 * the device's own setting rules again. Profil may arrive as a fragment long after this
 * ran, so the change is delegated and the radios are re-checked whenever a screen shows.
 * It imports nothing from the loader, which does nothing on pages it cannot drive.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';

const root = document.documentElement;

function stored() {
	try {
		const mode = localStorage.getItem('obrokColorMode');
		return mode === 'light' || mode === 'dark' ? mode : 'system';
	} catch (_e) {
		return 'system';
	}
}

function sync() {
	const choice = stored();
	const radios = document.querySelectorAll('[data-mode-control] input[name="mode"]');
	for (let i = 0; i < radios.length; i++) {
		radios[i].checked = radios[i].value === choice;
	}
}

/** The mode a choice ('light', 'dark' or 'system') puts on the root; "system" follows the device. */
export function resolveMode(choice, prefersDark) {
	if (choice === 'light' || choice === 'dark') {
		return choice;
	}

	return prefersDark ? 'dark' : 'light';
}

const deviceDark = () => Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

function apply(choice) {
	const mode = resolveMode(choice, deviceDark());
	root.setAttribute('data-mode', mode);
	try {
		if (choice === 'system') {
			localStorage.removeItem('obrokColorMode');
		} else {
			localStorage.setItem('obrokColorMode', choice);
		}
	} catch (_e) {
		// private browsing: the choice lasts as long as the page does
	}
	const meta = document.querySelector('meta[name=theme-color]');
	if (meta && meta.getAttribute('data-dark')) {
		meta.setAttribute('content', meta.getAttribute(mode === 'dark' ? 'data-dark' : 'data-light'));
	}
}

document.addEventListener('change', event => {
	const input = event.target;
	if (input && input.name === 'mode' && input.closest && input.closest('[data-mode-control]')) {
		apply(input.value);
	}
});
document.addEventListener('DOMContentLoaded', sync);
onScreenShown(sync);
onScreenMorphed(sync);
if (document.readyState !== 'loading') {
	sync();
}
