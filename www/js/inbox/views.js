/**
 * Informace's two views, the notifications and the message form (Zprávy), switched like
 * Místa's. The view is the address: Informace is the screen's own path, Zprávy `/zpravy`
 * under it. Each pill is a link to its address, followed as a full page load without
 * JavaScript; here a plain click pushes the address instead, so every tap is one Back;
 * Back (popstate), a show of the screen (screen:shown), a morph and the wiring read the
 * view from the address again. Both panes stay in the document, so a half-typed message
 * survives a switch, and both work offline, so unlike Místa there is no offline view.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';
import {setAttr} from '../ui/dom.js';
import {markSelected, nextTabIndex} from '../ui/tabs.js';

const INBOX_PATH = '/zpravy';

/** The view the address names on the Informace screen `screen`, or null for another address. */
export function infoViewFromPath(pathname, screen) {
	if (!screen) {
		return null;
	}
	if (pathname === screen) {
		return 'info';
	}
	return pathname === screen + INBOX_PATH ? 'inbox' : null;
}

/** The address of `view` on the Informace screen `screen`, the inverse of infoViewFromPath(). */
export function infoViewPath(view, screen) {
	return view === 'inbox' ? screen + INBOX_PATH : screen;
}

/** Wires the pills of one [data-info-root]. */
export function setupViews(root) {
	const section = root.closest('[data-screen]');
	// the screen's own path; the Zprávy address is built on it
	const screen = section ? section.dataset.screen : '';
	let view = root.dataset.view === 'inbox' ? 'inbox' : 'info';

	function tabs() {
		return Array.from(root.querySelectorAll('[role="tab"][data-info-view]'));
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
			const shown = tab.dataset.infoView === next;
			markSelected(tab, shown, true);
			// the pills are links to the two addresses; the shown one is the current page
			if (shown) {
				setAttr(tab, 'aria-current', 'page');
			} else if (tab.hasAttribute('aria-current')) {
				tab.removeAttribute('aria-current');
			}
		});
	}

	/**
	 * A pill's choice (a tap or an arrow key): the view is shown and its address pushed with
	 * the query on show, so Back returns to the view before. The view already on show
	 * pushes nothing: only the path is compared.
	 */
	function select(next) {
		setView(next);
		if (!screen) {
			return;
		}
		const path = infoViewPath(next, screen);
		if (location.pathname !== path) {
			history.pushState({screen, infoView: next}, '', path + location.search);
		}
	}

	/**
	 * The view the address names. The worker stores one copy of the screen for both
	 * addresses, so its data-view may be the other view's: only the address counts. On an
	 * address that is not this screen's (a morph of the screen while hidden), the view on
	 * show stays.
	 */
	function syncFromUrl() {
		setView(infoViewFromPath(location.pathname, screen) || view);
	}

	root.addEventListener('click', event => {
		const tab = event.target.closest('[role="tab"][data-info-view]');
		if (!tab) {
			return;
		}
		// The pills are links, which is how a reader without JavaScript switches views. A
		// plain primary click is this script's, and the link is not followed (a load would
		// throw away a half-typed message); one with a modifier key (a new tab) is the
		// browser's, and the screen does nothing.
		if (tab.matches('a[href]')) {
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
		}
		select(tab.dataset.infoView);
	});

	// arrow keys walk the two tabs, as the tablist pattern expects
	root.addEventListener('keydown', event => {
		const tab = event.target.closest && event.target.closest('[role="tab"][data-info-view]');
		if (!tab) {
			return;
		}
		// a link answers Enter alone; Space presses it here as it would a button, once for a
		// held key (the click handler above then takes it)
		if (event.key === ' ' && tab.matches('a[href]')) {
			event.preventDefault();
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
		select(list[next].dataset.infoView);
		list[next].focus();
	});

	// data-morph-keep keeps data-view and the pills' aria-selected and aria-current, and the
	// server writes no tabindex; the view is re-asserted all the same, since a pill the
	// morph replaced is a new node with the server's state
	onScreenMorphed(target => {
		if (target.contains && target.contains(root)) {
			syncFromUrl();
		}
	});

	syncFromUrl();

	// A screen already wired reads its address again each time it is shown: a tab tap
	// comes back to the address the screen was left on (the loader's lastAddress).
	// Registered while the root is being wired, so the screen:shown that wires it, if any,
	// does not reach it.
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
		const next = infoViewFromPath(location.pathname, screen);
		if (next !== null) {
			setView(next);
		}
	});
}
