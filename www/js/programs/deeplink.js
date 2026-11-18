/**
 * The deep links into one programme, and the view a write was sent from.
 *
 * The old deep link into a single programme keeps working, and opens its detail. A
 * register or unregister is a form POST, and its redirect lands on the programme's plain
 * deep link, which opens the timeline. The view the reader acted from is noted here on
 * submit (pgReturn:<slug>) and taken once on landing, so the reader comes back to Přehled
 * or Moje with the sheet open again. A deep link without the note, or with one for another
 * programme or older than a minute, behaves as it always did.
 */

import {hashTarget, returnView} from './maths.js';

export function setup(ctx) {
	const {root} = ctx;
	const returnKey = 'pgReturn:' + ctx.slug;

	ctx.noteReturn = id => {
		try {
			sessionStorage.setItem(returnKey, JSON.stringify({id: String(id), view: ctx.view, at: Date.now()}));
		} catch (_e) {
			// private browsing: the landing opens the timeline, as a plain deep link does
		}
	};

	function takeReturn(id) {
		let note = null;
		try {
			note = JSON.parse(sessionStorage.getItem(returnKey) || 'null');
			sessionStorage.removeItem(returnKey);
		} catch (_e) {
			note = null;
		}

		return returnView(note, id, Date.now());
	}

	ctx.fromHash = () => {
		if (location.hash === '#muj-program') {
			// where a login from the list's own form lands
			ctx.showView('list');
			return;
		}
		const target = hashTarget(location.hash);
		if (!target) {
			return;
		}
		// the homepage's next-programme card: Můj program, on that programme, open;
		// otherwise the redirect after a register or unregister: back on the view it was sent from
		const view = target.mine ? 'list' : takeReturn(target.id);
		if (view === 'list') {
			ctx.pendingListTarget = target.id;
			if (ctx.view === 'list') {
				ctx.enterList('list', false);
			} else {
				ctx.showView('list');
			}
			ctx.openSheet(target.id, false, null);
		} else if (view === 'overview') {
			ctx.showView('overview');
			ctx.scrollToItem('overview', target.id);
			ctx.openSheet(target.id, false, null);
		} else {
			ctx.openSheet(target.id, true, null);
		}
	};

	// A double tap must not send the write twice: the button goes disabled on submit.
	// A page restored from the back-forward cache keeps its disabled button, so it is
	// enabled again on pageshow, as the admin form's is.
	root.addEventListener('submit', event => {
		const form = event.target.closest ? event.target.closest('[data-pg-write]') : null;
		if (!form) {
			return;
		}
		ctx.noteReturn(form.dataset.pgWrite);
		form.querySelectorAll('button').forEach(button => {
			button.disabled = true;
		});
	});
	window.addEventListener('pageshow', () => {
		root.querySelectorAll('[data-pg-write] button[disabled]').forEach(button => {
			button.disabled = false;
		});
	});

	window.addEventListener('hashchange', () => {
		if (document.contains(root) && !root.closest('[data-screen][hidden]')) {
			ctx.fromHash();
		}
	});
}
