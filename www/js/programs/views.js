/** The three views, Přehled, Harmonogram and Moje, behind one tab strip. */

import {markSelected} from '../ui/tabs.js';
import {isList} from './daylist.js';

export function setup(ctx) {
	const {root} = ctx;

	ctx.viewTabs = () => Array.from(root.querySelectorAll('[data-pg-view]'));

	ctx.showView = view => {
		// each list's offset is remembered as the reader leaves it (daylist.js)
		const was = ctx.view;
		if (isList(was) && view !== was) {
			ctx.leaveList(was);
		}
		ctx.view = view;
		if (root.dataset.view !== view) {
			root.dataset.view = view;
		}
		ctx.viewTabs().forEach(tab => {
			// roving tabindex: only the selected tab is in the tab order
			markSelected(tab, tab.dataset.pgView === view, false);
		});
		if (view === 'timeline' && ctx.pageKey) {
			// the strip was hidden with its view and measured nothing while it was;
			// now it can bring the current chip round
			ctx.markChip('timeline', ctx.pageKey);
		}
		if (isList(view)) {
			// The saved offset is only written when the reader leaves the list, so
			// it is older than where they are as soon as they scroll again. It is
			// therefore restored on a genuine switch into the list and nowhere
			// else: showView() also runs after a background morph, with the
			// reader sitting in the list — and possibly on another screen
			// entirely, whose scroll it would be the one to move.
			ctx.enterList(view, view !== was);
		}
	};
}
