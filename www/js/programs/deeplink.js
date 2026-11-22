/**
 * The address on the way in, and the view a write was sent from.
 *
 * The path decides the view, the hash decides the programme: ctx.fromHash() opens the sheet
 * `#section-<s>-program-<id>` names on the view the path names (views.js has already shown
 * it), and closes any open sheet when the hash names none. It runs at the wiring, on
 * screen:shown (ctx.syncFromUrl) and, through sheet.js, on popstate (which a same-document
 * hash change fires too); nothing listens for hashchange. The two old Moje hashes on the screen's own path (`#muj-program`,
 * `#muj-program-<id>`) are rewritten to Moje's address first.
 *
 * A register or unregister form carries `pohled`, the view the server rendered; on submit it
 * is overwritten with the view on show, and the write lands on that view's address (sent by
 * shell/write.js, which also disables the button while it is on its way).
 */

import {hashTarget, legacyTarget, viewPath} from './maths.js';

export function setup(ctx) {
	const {root} = ctx;

	/** The id of programme `id`'s sheet body, which is its sheet's hash; null for no such programme. */
	function bodyIdOf(id) {
		const body = root.querySelector('.sheet-body[data-pg-detail="' + id + '"]');

		return body ? body.id : null;
	}

	/**
	 * An old Moje hash on Harmonogram's path: the entry becomes Moje's address, with the
	 * sheet's hash for a programme the screen has, and Moje is shown.
	 */
	function fromLegacyHash() {
		const legacy = legacyTarget(location.hash);
		if (legacy === null || ctx.view !== 'timeline' || !ctx.screen) {
			return;
		}
		const bodyId = legacy.id === null ? null : bodyIdOf(legacy.id);
		history.replaceState(history.state, '', viewPath('list', ctx.screen) + location.search + (bodyId ? '#' + bodyId : ''));
		ctx.showView('list');
	}

	ctx.fromHash = () => {
		fromLegacyHash();
		const target = hashTarget(location.hash);
		if (!target) {
			ctx.closeSheet(true);
			return;
		}
		if (ctx.view === 'list') {
			// Moje lands on the programme, under its sheet
			ctx.pendingListTarget = target.id;
			ctx.enterList('list', false);
			ctx.openSheet(target.id, false, null);
		} else if (ctx.view === 'overview') {
			ctx.scrollToItem('overview', target.id);
			ctx.openSheet(target.id, false, null);
		} else {
			ctx.openSheet(target.id, true, null);
		}
	};

	// A register or unregister form carries the view on show: the answer lands on its address.
	// This listener on the root runs before shell/write.js's on the document, which sends it.
	root.addEventListener('submit', event => {
		const form = event.target.closest ? event.target.closest('form[data-write]') : null;
		const view = form ? form.querySelector('input[name="pohled"]') : null;
		if (view) {
			view.value = ctx.view;
		}
	});
}
