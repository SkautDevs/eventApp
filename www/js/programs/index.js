/**
 * The Program screen. Every [data-pg-root] is wired once (data-pg-ready): at evaluation for
 * a server-rendered screen, so it keeps working even if the loader never runs, and on
 * screen:shown for one the loader inserts. It must be wired only once the screen is
 * *visible* — the ruler's label-width measurement and every scrollLeft calculation return 0
 * inside a hidden subtree.
 *
 * The whole screen is one page: every day's timeline page, all three views (Přehled,
 * Harmonogram, Moje) and every programme detail are in the DOM, and this only decides
 * what is visible. Each root has its own `ctx`: the modules' shared state and the
 * functions each module attaches to it in setup(ctx). The setup order is the order the
 * listeners are registered in.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';
import {nextTabIndex} from '../ui/tabs.js';
import {setup as setupChips} from './chips.js';
import {isList, LIST_KINDS, setup as setupDaylist} from './daylist.js';
import {setup as setupDeeplink} from './deeplink.js';
import {hashTarget, legacyTarget, viewFromPath} from './maths.js';
import {setup as setupNowline} from './nowline.js';
import {setup as setupSheet} from './sheet.js';
import {setup as setupTimeline} from './timeline.js';
import {setup as setupViews} from './views.js';
import {setup as setupZoom} from './zoom.js';

export function init(root) {
	if (!root || root.dataset.pgReady === '1') {
		return;
	}
	root.dataset.pgReady = '1';

	const cssPx = name => parseFloat(getComputedStyle(root).getPropertyValue(name)) || 0;
	const section = root.closest('[data-screen]');
	// the screen's own path; its views' addresses are built on it (maths.js, viewPath())
	const screen = section ? section.dataset.screen : '';
	const ctx = {
		root,
		slug: root.dataset.pgEvent || '',
		screen,
		cssPx,
		stageWidth: cssPx('--tl-stage-width'),
		// What the reader owns rather than the server: which view is up and which page
		// the timeline is on. Held here as well as in the DOM, because a background
		// morph rewrites the DOM back to the server's defaults and this is what puts
		// the reader's own state back on top of it. The view is the address's: an offline
		// copy of the screen is stored once for all three addresses, so its data-view may
		// be another view's.
		view: viewFromPath(location.pathname, screen) ?? (root.dataset.view || 'timeline'),
		pageKey: null,
		hourWidth: cssPx('--hour-width'),
		// the programme Moje lands on when it is next entered: a deep link's, or one
		// opened on Harmonogram, so that switching to Moje lands on it rather than at the top
		pendingListTarget: null,
	};

	setupChips(ctx);
	setupTimeline(ctx);
	setupDaylist(ctx);
	setupViews(ctx);
	setupZoom(ctx);
	setupNowline(ctx);

	root.addEventListener('click', event => {
		const el = event.target.closest('[data-pg-view],[data-pg-page],[data-pg-close],[data-pg-open],[data-pg-zoom]');
		if (!el) {
			return;
		}
		const data = el.dataset;

		if (data.pgView !== undefined) {
			ctx.selectView(data.pgView);
		} else if (data.pgPage !== undefined) {
			if (isList(data.pgKind)) {
				ctx.scrollToDay(data.pgKind, data.pgPage);
			} else {
				ctx.showPageByKey(data.pgKind, data.pgPage);
			}
		} else if (data.pgClose !== undefined) {
			ctx.closeSheet();
		} else if (data.pgOpen !== undefined) {
			ctx.openSheet(data.pgOpen, false, el);
		} else if (data.pgZoom !== undefined) {
			// a button press has no finger to anchor on, so hold the middle
			ctx.setZoom(ctx.hourWidth * (data.pgZoom === 'in' ? 1.25 : 1 / 1.25));
		}
	});

	// Still on the document rather than on the root: Escape has to work when focus
	// has ended up on <body>, which is outside the screen. The root is never removed
	// once inserted, so this listener has nothing to unbind — but it does have to
	// stand down while another screen is the one on show.
	document.addEventListener('keydown', event => {
		if (!document.contains(root) || root.closest('[data-screen][hidden]')) {
			return;
		}

		if (event.key === 'Escape') {
			ctx.closeSheet();
			return;
		}

		if (event.key === 'Tab' && ctx.trapped()) {
			ctx.cycleTab(event);
			return;
		}

		const target = event.target.closest ? event.target : null;
		if (!target) {
			return;
		}

		// arrow keys walk the tab strip, as the tablist pattern expects
		const tab = target.closest('[data-pg-view]');
		if (tab && (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'Home' || event.key === 'End')) {
			const list = ctx.viewTabs();
			const next = nextTabIndex(event.key, list.indexOf(tab), list.length);
			event.preventDefault();
			ctx.selectView(list[next].dataset.pgView);
			list[next].focus();
			return;
		}
		// A list row needs no key handling of its own any more. The control in it is
		// the inner <button class="pl-open">, which answers Enter and Space itself;
		// the branch that used to be here fired on the <article> and handed
		// closeSheet() that as the opener, so focus came back one level out of where
		// the reader had left it. The article keeps tabindex="-1" all the same — it
		// is where focus returns to after a pointer press on the card.
	});

	setupDeeplink(ctx);
	setupSheet(ctx);

	/**
	 * After a background morph the DOM carries the server's defaults again: the
	 * timeline's own first page, no zoom, the timeline view. Everything the reader
	 * owns goes back on top of it here — the morph itself only preserved what the
	 * server never writes.
	 */
	function afterMorph() {
		root.style.setProperty('--hour-width', ctx.hourWidth + 'px');
		ctx.unwatchLists();
		ctx.showView(ctx.view);
		if (ctx.pageKey) {
			ctx.showPageByKey('timeline', ctx.pageKey);
		}
		LIST_KINDS.forEach(kind => {
			ctx.setCurrentDay(kind, null);
		});
		ctx.syncZoomButtons();
		ctx.applyTickStep();
		ctx.updateNow();
	}

	// A background morph rewrites the screen to the server's defaults; this is
	// where the reader's own state goes back on top of it.
	onScreenMorphed(target => {
		if (target.contains && target.contains(root)) {
			afterMorph();
		}
	});

	// the scale outlives paging and view switching, but not the session
	ctx.restoreZoom();
	ctx.showPage('timeline', Math.max(ctx.activeIndex('timeline'), 0));
	// each list's strip opens on the day at the top of its scroll; from here on the
	// observer marks whichever day the reader has scrolled to
	LIST_KINDS.forEach(kind => {
		const first = ctx.listDays(kind)[0];
		ctx.setCurrentDay(kind, first ? first.dataset.key : null);
	});
	ctx.showView(ctx.view);
	ctx.updateNow();
	// a deep link (or an old Moje hash) names its own place; only a plain landing goes to "now"
	if (hashTarget(location.hash) === null && legacyTarget(location.hash) === null) {
		ctx.openOnNow();
	}
	ctx.fromHash();

	// A screen already wired reads its address again each time it is shown: a tab tap
	// comes back to the address the screen was left on, Back from another screen to a
	// sheet's entry opens that sheet, and the loader's deep link (Domů's next-programme
	// card) pushes its address and dispatches no hashchange.
	// Registered here, while the root is being wired, so the screen:shown that wires it,
	// if any, does not reach it: the wiring above has read the address itself.
	onScreenShown(target => {
		if (target.contains && target.contains(root)) {
			ctx.syncFromUrl();
		}
	});
}

/** Wires every Program screen inside `scope`; safe to call repeatedly on the same screen. */
function initAll(scope) {
	scope.querySelectorAll('[data-pg-root]').forEach(init);
}

// A server-rendered Program screen wires itself, so the screen keeps working even
// if the loader never runs. Anything the loader inserts, the loader announces.
function ready(fn) {
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', fn);
	} else {
		fn();
	}
}

ready(() => {
	initAll(document);
});

onScreenShown(initAll);
