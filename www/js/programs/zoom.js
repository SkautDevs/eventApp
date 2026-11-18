/**
 * The hour-scale zoom. Only --hour-width moves. Every position and width on the grid is
 * already a multiple of it, so setting the property IS the zoom, and row heights, card
 * heights, type sizes and the stage column stay put. transform: scaleX() would have been
 * the cheap way to do this and would have stretched every programme name along with the
 * axis. The + and − buttons, two fingers on the grid and ctrl+wheel all zoom; the scale is
 * remembered per event for the tab (pgZoom:<slug>), and the ruler thins its labels when an
 * hour gets too narrow for them.
 */

import {setDisabled} from '../ui/dom.js';
import {span} from '../ui/pinch.js';
import {clampZoom, hourStep, tickThreshold, zoomScroll} from './maths.js';

export function setup(ctx) {
	const {root} = ctx;
	const zoomKey = 'pgZoom:' + ctx.slug;
	// the bounds live in the stylesheet, next to the property they bound
	const zoomMin = ctx.cssPx('--hour-width-min');
	const zoomMax = ctx.cssPx('--hour-width-max');

	function activeScroll() {
		return root.querySelector('.tl-page.is-active .tl-scroll') || root.querySelector('.tl-scroll');
	}

	/**
	 * How much room one hour has to have for its label to be worth drawing.
	 * Measured off a rendered label rather than guessed, because the two
	 * events do not share a font: 14px/700 "14:00" is 37.9px in Montserrat
	 * and 35.4px in themix, and a threshold right for one would be wrong
	 * for the other. Re-measured once the webfont lands.
	 */
	let tickReach = 0;

	function tickLabelReach() {
		if (tickReach === 0) {
			let widest = 0;
			root.querySelectorAll('.tl-page.is-active .tl-tick-label').forEach(label => {
				widest = Math.max(widest, label.getBoundingClientRect().width);
			});
			if (widest > 0) {
				tickReach = tickThreshold(widest);
			}
		}
		return tickReach || tickThreshold(0);
	}

	ctx.applyTickStep = () => {
		const step = hourStep(ctx.hourWidth, tickLabelReach());
		if (root.dataset.hourStep !== step) {
			root.dataset.hourStep = step;
		}
	};

	ctx.syncZoomButtons = () => {
		root.querySelectorAll('[data-pg-zoom]').forEach(button => {
			setDisabled(button, button.dataset.pgZoom === 'in' ? ctx.hourWidth >= zoomMax - 0.01 : ctx.hourWidth <= zoomMin + 0.01);
		});
	};

	/**
	 * Rescale the hour, keeping the moment under anchorClientX where it is.
	 * Without that the grid leaps sideways on every step: the anchor's
	 * distance into the time axis grows with the scale, so scrollLeft has
	 * to grow with it. Omit the anchor to hold the centre of the grid.
	 */
	ctx.setZoom = (next, anchorClientX) => {
		next = clampZoom(next, zoomMin, zoomMax);
		const box = activeScroll();
		let scroll = 0;
		if (box) {
			const rect = box.getBoundingClientRect();
			const anchor = anchorClientX === undefined ? rect.width / 2 : anchorClientX - rect.left;
			scroll = zoomScroll(box.scrollLeft, anchor, ctx.stageWidth, ctx.hourWidth, next);
		}
		ctx.hourWidth = next;
		root.style.setProperty('--hour-width', next + 'px');
		if (box) {
			// assigning scrollLeft flushes the layout the new width implies first
			box.scrollLeft = scroll;
		}
		try {
			sessionStorage.setItem(zoomKey, String(next));
		} catch (_e) {
			// private browsing: the scale simply does not outlive the page
		}
		ctx.syncZoomButtons();
		ctx.applyTickStep();
	};

	ctx.restoreZoom = () => {
		let stored = null;
		try {
			stored = sessionStorage.getItem(zoomKey);
		} catch (_e) {
			stored = null;
		}
		const value = parseFloat(stored);
		if (Number.isFinite(value)) {
			ctx.hourWidth = clampZoom(value, zoomMin, zoomMax);
			root.style.setProperty('--hour-width', ctx.hourWidth + 'px');
		}
		ctx.syncZoomButtons();
		ctx.applyTickStep();
	};

	/**
	 * One rescale per frame at most.
	 *
	 * A pinch delivers pointermove faster than the screen refreshes, and every
	 * setZoom writes --hour-width and then reads scrollLeft back, which forces the
	 * layout the new width implies. Doing that per event is doing it several times
	 * for one painted frame. The maths is untouched — the scale a pinch asks for is
	 * absolute (the span between the fingers against the span they started at) and
	 * the anchor is the live midpoint, so applying only the newest of the requests
	 * that arrived within a frame lands in exactly the same place, once.
	 */
	let zoomFrame = 0;
	let zoomWanted = null;

	ctx.requestZoom = (next, anchorClientX) => {
		zoomWanted = {next: next, anchor: anchorClientX};
		if (zoomFrame) {
			return;
		}
		zoomFrame = requestAnimationFrame(() => {
			zoomFrame = 0;
			const wanted = zoomWanted;
			zoomWanted = null;
			ctx.setZoom(wanted.next, wanted.anchor);
		});
	};

	// two fingers on the grid scale the axis, anchored between them
	const pointers = new Map();
	let pinch = null;

	// null under two pointers; the pinch re-bases on every pointer-down that leaves two or more
	function pinchSpan() {
		const list = Array.from(pointers.values());
		if (list.length < 2) {
			return null;
		}
		return span(list[0], list[1]);
	}

	root.addEventListener('pointerdown', event => {
		if (event.pointerType !== 'touch' || !event.target.closest('.tl-scroll')) {
			return;
		}
		pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
		const fingers = pinchSpan();
		if (fingers && fingers.dist > 0) {
			pinch = {dist: fingers.dist, hourWidth: ctx.hourWidth};
		}
	});

	root.addEventListener('pointermove', event => {
		if (!pointers.has(event.pointerId)) {
			return;
		}
		pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
		const fingers = pinchSpan();
		if (!pinch || !fingers || fingers.dist === 0) {
			return;
		}
		// the box may also have panned under the gesture; setting scrollLeft
		// from the live midpoint each frame is what keeps the anchor honest
		ctx.requestZoom(pinch.hourWidth * (fingers.dist / pinch.dist), fingers.midX);
	});

	function releasePointer(event) {
		pointers.delete(event.pointerId);
		if (pointers.size < 2) {
			pinch = null;
		}
	}

	root.addEventListener('pointerup', releasePointer);
	root.addEventListener('pointercancel', releasePointer);

	// ctrl+wheel is what a desktop reader will try first
	root.addEventListener(
		'wheel',
		event => {
			if (!event.ctrlKey || !event.target.closest('.tl-scroll')) {
				return;
			}
			event.preventDefault();
			ctx.setZoom(ctx.hourWidth * (event.deltaY < 0 ? 1.1 : 1 / 1.1), event.clientX);
		},
		{passive: false},
	);

	// a webfont landing changes what a label measures, and with it the scale
	// at which the ruler has to start thinning
	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(() => {
			tickReach = 0;
			ctx.applyTickStep();
		});
	}
}
