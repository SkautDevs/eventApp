/**
 * Writes replace their history entry. A form carrying data-write (the register and
 * unregister in a programme's sheet, Profil's pairing start and logout) is sent here with
 * `X-Write: 1`; the server answers `{"next": "<address>"}` (App\Http\WriteResponse) where a
 * plain form would get a redirect, and the page lands there with location.replace(): Back
 * then goes to what was under the entry the write was sent from, never to a page from
 * before the write. The worker still sees the POST and purges its stored pages first.
 *
 * Without JavaScript, or without fetch, the form posts as it always did. The button is
 * disabled while the write is on its way, so a double tap sends it once. Any other answer
 * (a 403 refusal page, a 5xx) is sent again as the plain form, so the reader sees the
 * server's own page; a fetch that rejects (no worker and no network) changed nothing, so
 * the button comes back with the offline toast.
 *
 * Event order: shell/confirm.js stops a data-confirm button's first tap at click, so only
 * the confirming tap reaches submit, and the Program root's own submit listener (the
 * `pohled` field, programs/deeplink.js) runs before this one on the document.
 */

import {showOfflineToast} from './toast.js';

/** A programme sheet's hash, as programs/maths.js's hashTarget() reads it. */
const PROGRAMME_HASH = /^#section-\d+-program-\d+$/;

/** The answer's `next` resolved against `base`, when it is a string on `origin`; else null. */
export function nextOf(body, origin, base) {
	if (!body || typeof body.next !== 'string') {
		return null;
	}
	let url;
	try {
		url = new URL(body.next, base);
	} catch {
		return null;
	}

	return url.origin === origin ? url.href : null;
}

/** Whether `next` has a hash and is `current` apart from both hashes: location.replace() would not load it. */
export function sameDocument(next, current) {
	const to = new URL(next);
	const from = new URL(current);
	if (to.hash === '') {
		return false;
	}
	to.hash = '';
	from.hash = '';

	return to.href === from.href;
}

/**
 * The state the reloaded entry keeps. A `next` naming a programme lands on the sheet's own
 * entry, which keeps its pgOverlay: the sheet reopens on it without another push. Any
 * other `next` (#offline, a plain address) shows no sheet, so the entry stops being one.
 */
export function reloadState(next, state) {
	if (!state || PROGRAMME_HASH.test(new URL(next).hash)) {
		return state;
	}

	const kept = {};
	for (const key of Object.keys(state)) {
		if (key !== 'pgOverlay') {
			kept[key] = state[key];
		}
	}

	return kept;
}

/**
 * Lands on `next` in place of the current entry. A hash-only difference is a fragment
 * navigation for location.replace(), which would leave the stale page up: that one is
 * written onto the entry and reloaded.
 */
export function follow(next) {
	if (sameDocument(next, location.href)) {
		history.replaceState(reloadState(next, history.state), '', next);
		location.reload();

		return;
	}
	location.replace(next);
}

function setButtons(form, disabled) {
	form.querySelectorAll('button').forEach(button => {
		button.disabled = disabled;
	});
}

document.addEventListener('submit', event => {
	const form = event.target.closest ? event.target.closest('form[data-write]') : null;
	if (!form || event.defaultPrevented || !window.fetch) {
		return;
	}
	event.preventDefault();
	setButtons(form, true);
	fetch(form.action, {
		method: 'POST',
		body: new URLSearchParams(new FormData(form)),
		headers: {'X-Write': '1', Accept: 'application/json'},
		credentials: 'same-origin',
	})
		.then(response => (response.status === 200 ? response.json().catch(() => null) : null))
		.then(
			body => {
				const next = nextOf(body, location.origin, location.href);
				if (next === null) {
					// a refusal page, a server error: the plain form shows the server's own answer
					form.submit();

					return;
				}
				follow(next);
			},
			() => {
				// never reached the server, nor a worker to answer for it: nothing changed
				setButtons(form, false);
				showOfflineToast();
			},
		);
});

// a page restored from the back-forward cache keeps a disabled button
window.addEventListener('pageshow', () => {
	document.querySelectorAll('[data-write] button[disabled]').forEach(button => {
		button.disabled = false;
	});
});
