/**
 * Místa's two views of one place, Google's interactive map and the handbook's plan,
 * switched like the Program screen's. A screen with both carries two tabs and opens on the
 * view the reader chose last in this tab; a screen with one keeps the view its markup
 * states. Nothing is ever detached: hiding the Google pane keeps its iframe running, which
 * is the point of the screen stack.
 */

import {onScreenMorphed} from '../events.js';
import {markSelected, nextTabIndex} from '../ui/tabs.js';
import {session} from './plan.js';

/** The view a screen with tabs opens on: offline the Google map is a blank box, so the plan. */
export function openingView(online, stored) {
	if (!online) {
		return 'plan';
	}
	return stored === 'plan' || stored === 'google' ? stored : 'google';
}

/** Wires the tabs of one map root; `plan` is its setupPlan(), sized whenever the plan shows. */
export function setupViews(root, slug, plan) {
	const viewKey = 'mapView:' + slug;
	const hasTabs = root.querySelector('[role="tab"][data-map-view]') !== null;
	let view = root.dataset.view || 'google';

	function tabs() {
		return Array.from(root.querySelectorAll('[role="tab"][data-map-view]'));
	}

	function setView(next) {
		view = next;
		if (root.dataset.view !== next) {
			root.dataset.view = next;
		}
		tabs().forEach(tab => {
			markSelected(tab, tab.dataset.mapView === next, true);
		});
		if (next === 'plan') {
			plan.apply(plan.zoom());
		}
	}

	function choose(next) {
		setView(next);
		session(viewKey, next);
	}

	root.addEventListener('click', event => {
		const el = event.target.closest('[data-map-view],[data-plan-zoom]');
		if (!el || el.dataset.mapView === undefined) {
			return;
		}
		choose(el.dataset.mapView);
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
		const list = tabs();
		const next = nextTabIndex(event.key, list.indexOf(tab), list.length);
		if (next === null) {
			return;
		}
		event.preventDefault();
		choose(list[next].dataset.mapView);
		list[next].focus();
	});

	// data-morph-keep keeps data-view, but the tabs' aria-selected and tabindex are the
	// server's again after a morph, so the reader's view is re-asserted on them
	onScreenMorphed(target => {
		if (target.contains && target.contains(root)) {
			setView(view);
		}
	});

	if (hasTabs) {
		setView(openingView(navigator.onLine !== false, session(viewKey)));
	} else {
		setView(view);
	}
}
