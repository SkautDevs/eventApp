/**
 * The place the address names (`?misto=<id>`), put on show on a Místa screen that may have
 * been rendered for another address: the stacked screen the loader shows for a pin, or the
 * stored page the worker answers with offline. The server draws every marker and the
 * centred iframe for its own request; this does the same for the address on show.
 *
 * - The marker of the place is shown and every other one hidden.
 * - Google's iframe is centred on the place (the same `src` as `Map\Embed::centred()`), or
 *   put back on its plain embed for an address without one; it is written only when it
 *   differs, since every write reloads Google's map.
 * - On the plan the canvas is zoomed to PLACE_ZOOM and scrolled to the marker, once per
 *   place and view while Místa is on show, so a reader who zoomed out is not snapped back
 *   by a morph or a repeated popstate. That zoom is not stored, and it lasts as long as the
 *   place: an address without one, or Místa hidden, puts the reader's own scale
 *   (`planZoom:<slug>`, or 1) back with plan.restore(), which writes nothing either.
 *
 * It acts only on an address of its own Místa: a popstate that leaves the screen runs while
 * Místa is still on show, and must not touch its markers or reload its iframe.
 */

import {onScreenHidden, onScreenMorphed, onScreenShown} from '../events.js';
import {mapViewFromPath} from './views.js';

/** The plan's width, as a multiple of its box, when it opens on a place. */
export const PLACE_ZOOM = 2.5;

const PLACE_ID = /^[1-9][0-9]{0,8}$/;

/** The place a query string names (`misto`, a plain positive id), or null. */
export function placeFromSearch(search) {
	const value = new URLSearchParams(search).get('misto');

	return value !== null && PLACE_ID.test(value) ? Number(value) : null;
}

/** Google's embed centred on (lat, lng), spelled as the server spells it (Map\Embed::centred()). */
export function centredEmbed(embed, lat, lng) {
	return embed + (embed.includes('?') ? '&' : '?') + 'll=' + lat + ',' + lng + '&z=18';
}

function clamp(value, min, max) {
	return Math.min(Math.max(value, min), max);
}

/** Where a box of boxW × boxH scrolls to put the canvas point (x, y) in its middle, kept inside the canvas. */
export function centreScroll(x, y, boxW, boxH, canvasW, canvasH) {
	return {
		left: clamp(x - boxW / 2, 0, Math.max(canvasW - boxW, 0)),
		top: clamp(y - boxH / 2, 0, Math.max(canvasH - boxH, 0)),
	};
}

/**
 * The place of one map root; `plan` is its setupPlan(). sync() puts the address's place on
 * show; listen() runs it on the moments below, and is called after setupViews(), so a
 * popstate reaches the views first.
 */
export function setupPlace(root, plan) {
	const section = root.closest('[data-screen]');
	const screen = section ? section.dataset.screen : '';
	// `<id>|plan` once the plan was zoomed and centred on that place; cleared when Místa is
	// hidden or the address names no place on the plan
	let centred = null;
	// the plan's image has not loaded yet, and its load will centre it
	let waiting = false;
	// the plan is at PLACE_ZOOM for a place, not at the reader's own scale
	let zoomed = false;

	function visible() {
		return document.contains(root) && !root.closest('[data-screen][hidden]');
	}

	/** Shows the marker of `id` and hides every other one; returns it, or null. */
	function showMarker(id) {
		let shown = null;
		root.querySelectorAll('[data-plan-canvas] [data-place]').forEach(marker => {
			const hidden = Number(marker.dataset.place) !== id;
			if (marker.hidden !== hidden) {
				marker.hidden = hidden;
			}
			if (!hidden) {
				shown = marker;
			}
		});

		return shown;
	}

	function centreIframe(id) {
		const iframe = root.querySelector('iframe[data-embed]');
		if (!iframe) {
			return;
		}
		const embed = iframe.dataset.embed;
		const place = id === null ? null : root.querySelector('[data-map-places] [data-place="' + id + '"]');
		const wanted = place ? centredEmbed(embed, place.dataset.lat, place.dataset.lng) : embed;
		if (iframe.getAttribute('src') !== wanted) {
			iframe.src = wanted;
		}
	}

	/**
	 * Zooms the plan to PLACE_ZOOM and scrolls the marker to the box's middle. An image not
	 * loaded yet has no height, so a marker's top would read 0: its load centres it then.
	 */
	function centrePlan(id, marker) {
		const key = id + '|plan';
		if (!marker || root.dataset.view !== 'plan' || !visible() || key === centred) {
			return;
		}
		plan.apply(PLACE_ZOOM);
		zoomed = true;
		const img = root.querySelector('[data-plan-img]');
		if (img && (!img.complete || img.naturalHeight === 0)) {
			if (!waiting) {
				waiting = true;
				img.addEventListener(
					'load',
					() => {
						waiting = false;
						sync();
					},
					{once: true},
				);
			}
			return;
		}
		const box = plan.box();
		const canvas = root.querySelector('[data-plan-canvas]');
		if (!box || !canvas || canvas.offsetHeight === 0) {
			return;
		}
		// the marker's centre: its box is pulled back by half its size (map.css)
		const at = centreScroll(
			marker.offsetLeft + marker.offsetWidth / 2,
			marker.offsetTop + marker.offsetHeight / 2,
			box.clientWidth,
			box.clientHeight,
			canvas.offsetWidth,
			canvas.offsetHeight,
		);
		box.scrollLeft = at.left;
		box.scrollTop = at.top;
		centred = key;
	}

	/** Puts the reader's own scale back once a place's zoom is no longer wanted. */
	function unzoom() {
		centred = null;
		if (zoomed) {
			zoomed = false;
			plan.restore();
		}
	}

	/** Puts the place of the address on show, when the address is this Místa's. */
	function sync() {
		if (mapViewFromPath(location.pathname, screen) === null) {
			return;
		}
		const id = placeFromSearch(location.search);
		const marker = showMarker(id);
		centreIframe(id);
		// no place on the plan (none on the address, or one off the drawing): no place zoom
		if (marker === null) {
			unzoom();
			return;
		}
		centrePlan(id, marker);
	}

	function listen() {
		onScreenShown(target => {
			if (target.contains && target.contains(root)) {
				sync();
			}
		});
		onScreenHidden(target => {
			if (target.contains && target.contains(root)) {
				unzoom();
			}
		});
		// the markup the morph brings is the server's: a marker it added is hidden there
		onScreenMorphed(target => {
			if (target.contains && target.contains(root) && mapViewFromPath(location.pathname, screen) !== null) {
				showMarker(placeFromSearch(location.search));
			}
		});
		window.addEventListener('popstate', () => {
			if (visible()) {
				sync();
			}
		});
		sync();
	}

	return {sync, listen};
}
