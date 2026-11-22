/**
 * The three views, Přehled, Harmonogram and Moje, behind one tab strip. The view is the
 * address: Harmonogram is the screen's own path, Přehled `/prehled` and Moje `/moje` under
 * it. A pill pushes its address, so every tap is one Back; Back (popstate), a show of the
 * screen (screen:shown) and the wiring read the view from the address again.
 */

import {setAttr} from '../ui/dom.js';
import {markSelected} from '../ui/tabs.js';
import {isList} from './daylist.js';
import {viewFromPath, viewPath} from './maths.js';

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
			const shown = tab.dataset.pgView === view;
			// roving tabindex: only the selected tab is in the tab order
			markSelected(tab, shown, false);
			if (shown) {
				setAttr(tab, 'aria-current', 'page');
			} else if (tab.hasAttribute('aria-current')) {
				tab.removeAttribute('aria-current');
			}
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

	/**
	 * A pill's choice (a tap or an arrow key): the view is shown and its address pushed,
	 * so Back returns to the view before. The address already on show pushes nothing.
	 * The loader learns the new address on the next click (loader.js, noteAddress()).
	 */
	ctx.selectView = view => {
		ctx.showView(view);
		if (!ctx.screen) {
			return;
		}
		const path = viewPath(view, ctx.screen);
		if (location.pathname !== path) {
			history.pushState({screen: ctx.screen, pgView: view}, '', path);
		}
	};

	/** The view the address names, then the programme its hash names. */
	ctx.syncFromUrl = () => {
		const view = viewFromPath(location.pathname, ctx.screen);
		if (view !== null) {
			ctx.showView(view);
		}
		ctx.fromHash();
	};

	// Back or Forward between two views of this screen: the loader keeps the screen up
	// (show() returns early for the current one), and this switches the view. Registered
	// before the sheet's listener, which closes whatever the entry left open.
	window.addEventListener('popstate', () => {
		if (!document.contains(root) || root.closest('[data-screen][hidden]')) {
			return;
		}
		const view = viewFromPath(location.pathname, ctx.screen);
		if (view !== null) {
			ctx.showView(view);
		}
	});
}
