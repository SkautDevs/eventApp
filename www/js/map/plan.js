/**
 * The handbook's own site plan, the view that works offline. It zooms by the width of its
 * canvas (the image and the place markers on it) inside a box that scrolls both ways — the
 * browser's own panning, no transform — and the anchor stays put, as on the timeline: the
 * + and − buttons zoom around the box's centre, two fingers around the point between them.
 * The image is the canvas's full width and a marker sits at its share of the canvas, so the
 * markers stay on the drawing at every scale. The scale is remembered per event for the tab.
 */

import {setDisabled} from '../ui/dom.js';
import {span} from '../ui/pinch.js';

const ZOOM_MIN = 1;
const ZOOM_MAX = 6;
const ZOOM_STEP = 1.4;

/** sessionStorage, read (one argument) or written; private browsing simply forgets. */
export function session(key, value) {
	try {
		if (value === undefined) {
			return sessionStorage.getItem(key);
		}
		sessionStorage.setItem(key, value);
	} catch (_e) {
		// the choice simply does not outlive the page
	}
	return null;
}

/** The plan's width as a multiple of its box: 1 to 6. */
export function clampZoom(value) {
	return Math.min(Math.max(value, ZOOM_MIN), ZOOM_MAX);
}

/**
 * Where the box scrolls to when the plan goes from `from` to `to` × the box, so that the
 * point (x, y) of the box, measured from its top left corner, stays where it is.
 */
export function planScroll(scrollLeft, scrollTop, x, y, from, to) {
	const ratio = to / from;
	const left = (scrollLeft + x) * ratio - x;
	const top = (scrollTop + y) * ratio - y;
	return {left: Math.max(left, 0), top: Math.max(top, 0)};
}

/**
 * Wires the plan of one map root: its zoom buttons, the pinch, the remembered scale. Returns
 * `apply(zoom, ax, ay)`, `restore()` (back to the remembered scale, 1 when none, writing
 * nothing: map/place.js undoes a place's zoom with it), the scale on show (`zoom()`) and
 * the scrolling box (`box()`).
 */
export function setupPlan(root, slug) {
	const zoomKey = 'planZoom:' + slug;
	let zoom = ZOOM_MIN;
	// the canvas's width is set once without the scroll maths: before that, the box
	// holds the plan at 100% whatever `zoom` says, so a ratio against it would be wrong
	let applied = false;

	/**
	 * Sets the canvas's width to `next` × the box, keeping the point under (ax, ay) where
	 * it is. It does not remember the scale: a pinch calls it on every pointermove, so
	 * its callers store it once the gesture or the tap is over (rememberZoom()).
	 */
	function applyZoom(next, ax, ay) {
		const plan = root.querySelector('[data-plan]');
		const canvas = root.querySelector('[data-plan-canvas]');
		if (!plan || !canvas) {
			return;
		}
		next = clampZoom(next);
		if (applied) {
			const rect = plan.getBoundingClientRect();
			const x = ax === undefined ? rect.width / 2 : ax - rect.left;
			const y = ay === undefined ? rect.height / 2 : ay - rect.top;
			const scroll = planScroll(plan.scrollLeft, plan.scrollTop, x, y, zoom, next);
			canvas.style.width = next * 100 + '%';
			plan.scrollLeft = scroll.left;
			plan.scrollTop = scroll.top;
		} else {
			canvas.style.width = next * 100 + '%';
			applied = true;
		}
		zoom = next;
		root.querySelectorAll('[data-plan-zoom]').forEach(button => {
			setDisabled(button, button.dataset.planZoom === 'in' ? zoom >= ZOOM_MAX - 0.01 : zoom <= ZOOM_MIN + 0.01);
		});
	}

	function rememberZoom() {
		session(zoomKey, String(zoom));
	}

	/** The scale the reader last chose for the tab, or 1. */
	function storedZoom() {
		const stored = parseFloat(session(zoomKey));
		return Number.isFinite(stored) ? clampZoom(stored) : ZOOM_MIN;
	}

	root.addEventListener('click', event => {
		const el = event.target.closest('[data-map-view],[data-plan-zoom]');
		// a view button is the views' own (map/views.js)
		if (!el || el.dataset.mapView !== undefined) {
			return;
		}
		applyZoom(zoom * (el.dataset.planZoom === 'in' ? ZOOM_STEP : 1 / ZOOM_STEP));
		rememberZoom();
	});

	// two fingers on the plan zoom it, anchored between them
	const pointers = new Map();
	let pinch = null;

	function fingers() {
		const list = Array.from(pointers.values());
		return span(list[0], list[1]);
	}

	root.addEventListener('pointerdown', event => {
		if (event.pointerType !== 'touch' || !event.target.closest('[data-plan]')) {
			return;
		}
		pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
		if (pointers.size === 2) {
			pinch = {dist: fingers().dist, zoom: zoom};
		}
	});
	root.addEventListener('pointermove', event => {
		if (!pointers.has(event.pointerId)) {
			return;
		}
		pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
		if (!pinch || pointers.size < 2) {
			return;
		}
		const now = fingers();
		if (now.dist > 0 && pinch.dist > 0) {
			applyZoom((pinch.zoom * now.dist) / pinch.dist, now.midX, now.midY);
		}
	});
	function release(event) {
		pointers.delete(event.pointerId);
		if (pinch && pointers.size < 2) {
			pinch = null;
			// written once per pinch, when it ends, not on every move
			rememberZoom();
		}
	}
	root.addEventListener('pointerup', release);
	root.addEventListener('pointercancel', release);

	zoom = storedZoom();

	return {
		apply: applyZoom,
		restore: () => applyZoom(storedZoom()),
		zoom: () => zoom,
		box: () => root.querySelector('[data-plan]'),
	};
}
