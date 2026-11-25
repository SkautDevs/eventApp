/**
 * Místa's two views of one place, Google's interactive map and the handbook's plan,
 * switched like the Program screen's. The view is the address: the map is the screen's own
 * path, the plan `/plan` under it. Each pill is a link to its address, followed as a full
 * page load without JavaScript; here a plain click pushes the address instead, so every
 * tap is one Back; Back (popstate), a show of the screen (screen:shown) and the wiring
 * read the view from the address again. A screen with one pane keeps the view its markup
 * states. Nothing is ever detached: hiding the Google pane keeps its iframe running, which
 * is the point of the screen stack.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';
import {setAttr} from '../ui/dom.js';
import {markSelected, nextTabIndex} from '../ui/tabs.js';

const PLAN_PATH = '/plan';

/** The view the address names on the Místa screen `screen`, or null for another address. */
export function mapViewFromPath(pathname, screen) {
	if (!screen) {
		return null;
	}
	if (pathname === screen) {
		return 'google';
	}
	return pathname === screen + PLAN_PATH ? 'plan' : null;
}

/** The address of `view` on the Místa screen `screen`, the inverse of mapViewFromPath(). */
export function mapViewPath(view, screen) {
	return view === 'plan' ? screen + PLAN_PATH : screen;
}

/** The view a screen with tabs opens on: offline the Google map is a blank box, so the plan. */
export function openingView(online, pathView) {
	if (!online) {
		return 'plan';
	}
	return pathView || 'google';
}

/** Wires the tabs of one map root; `plan` is its setupPlan(), sized whenever the plan shows. */
export function setupViews(root, plan) {
	const hasTabs = root.querySelector('[role="tab"][data-map-view]') !== null;
	const section = root.closest('[data-screen]');
	// the screen's own path; the plan's address is built on it
	const screen = section ? section.dataset.screen : '';
	let view = root.dataset.view || 'google';

	function tabs() {
		return Array.from(root.querySelectorAll('[role="tab"][data-map-view]'));
	}

	function visible() {
		return document.contains(root) && !root.closest('[data-screen][hidden]');
	}

	function setView(next) {
		view = next;
		if (root.dataset.view !== next) {
			root.dataset.view = next;
		}
		tabs().forEach(tab => {
			const shown = tab.dataset.mapView === next;
			markSelected(tab, shown, true);
			// the pills are links to the two addresses; the shown one is the current page
			if (shown) {
				setAttr(tab, 'aria-current', 'page');
			} else if (tab.hasAttribute('aria-current')) {
				tab.removeAttribute('aria-current');
			}
		});
		if (next === 'plan') {
			plan.apply(plan.zoom());
		}
	}

	/**
	 * A pill's choice (a tap, an arrow key or the offline note's button): the view is shown
	 * and its address pushed, so Back returns to the view before. The address already on
	 * show pushes nothing.
	 */
	function select(next) {
		setView(next);
		if (!hasTabs || !screen) {
			return;
		}
		const path = mapViewPath(next, screen);
		if (location.pathname !== path) {
			history.pushState({screen, mapView: next}, '', path);
		}
	}

	/**
	 * The view the address names, read on wiring and on each show. An offline copy of the
	 * screen is stored once for both addresses, so its data-view may be the other view's:
	 * only the address counts. Offline the bare address moves to the plan's, replacing
	 * the entry, since Google's map is a blank box then.
	 */
	function syncFromUrl() {
		const online = navigator.onLine !== false;
		const pathView = mapViewFromPath(location.pathname, screen);
		// on an address that is not this screen's, the view on show stays
		setView(openingView(online, pathView || view));
		if (!online && pathView === 'google') {
			history.replaceState(history.state, '', mapViewPath('plan', screen));
		}
	}

	root.addEventListener('click', event => {
		const el = event.target.closest('[data-map-view],[data-plan-zoom]');
		if (!el || el.dataset.mapView === undefined) {
			return;
		}
		// The pills are links, which is how a reader without JavaScript switches views. A
		// plain primary click is this script's, and the link is not followed (a full page
		// load would refetch Google's iframe); one with a modifier key (a new tab) is the
		// browser's, and the screen does nothing.
		if (el.matches('a[href]')) {
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
		}
		select(el.dataset.mapView);
		// the offline note's button moves the reader, so the focus goes with them
		if (!el.matches('[role="tab"]')) {
			const tab = root.querySelector('[role="tab"][data-map-view="' + el.dataset.mapView + '"]');
			if (tab) {
				tab.focus();
			}
		}
	});

	// arrow keys walk the two tabs, as the tablist pattern expects
	root.addEventListener('keydown', event => {
		const tab = event.target.closest && event.target.closest('[role="tab"][data-map-view]');
		if (!tab) {
			return;
		}
		// The pills were buttons, which Space pressed; a link answers Enter alone, so Space
		// presses it here (the click handler above then takes it).
		if (event.key === ' ' && tab.matches('a[href]')) {
			event.preventDefault();
			// a held key presses once, as a button's did
			if (!event.repeat) {
				tab.click();
			}
			return;
		}
		const list = tabs();
		const next = nextTabIndex(event.key, list.indexOf(tab), list.length);
		if (next === null) {
			return;
		}
		event.preventDefault();
		select(list[next].dataset.mapView);
		list[next].focus();
	});

	// data-morph-keep keeps data-view and the pills' aria-selected and aria-current, and the
	// server writes no tabindex; the reader's view is re-asserted all the same, since a
	// pill the morph replaced is a new node with the server's state
	onScreenMorphed(target => {
		if (target.contains && target.contains(root)) {
			setView(view);
		}
	});

	if (!hasTabs) {
		setView(view);
		return;
	}

	syncFromUrl();

	// A screen already wired reads its address again each time it is shown: a tab tap
	// comes back to the address the screen was left on. Registered while the root is
	// being wired, so the screen:shown that wires it, if any, does not reach it.
	onScreenShown(target => {
		if (target.contains && target.contains(root)) {
			syncFromUrl();
		}
	});

	// Back or Forward between the two views: the loader keeps the screen up, and this
	// switches the view, pushing nothing
	window.addEventListener('popstate', () => {
		if (!visible()) {
			return;
		}
		const next = mapViewFromPath(location.pathname, screen);
		if (next !== null) {
			setView(next);
		}
	});
}
