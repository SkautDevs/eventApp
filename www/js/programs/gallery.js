/**
 * The sheet's photos (programs.twig's `[data-gallery]`). A photo is transparent until it
 * has loaded (programs.css), so a reader sees the event's logo under it rather than a
 * photo painting in strips; this marks it `is-loaded`, which motion.css fades in. One that
 * fails to load is left alone, and the logo stays. The dots follow the strip as the reader
 * swipes it. Both are attributes the server also writes, so the template lists them in
 * `data-morph-keep` and a background morph leaves them as they are. Frames and dots are
 * keyed by their photo, so a morph that reorders the photos moves them, and the dots are
 * then matched to the strip's scroll again.
 */

import {onScreenMorphed} from '../events.js';

/** Which photo of `count` a strip scrolled to `scrollLeft` shows, each `frameWidth` wide. */
export function galleryIndex(scrollLeft, frameWidth, count) {
	if (frameWidth <= 0) {
		return 0;
	}

	return Math.min(Math.max(Math.round(scrollLeft / frameWidth), 0), count - 1);
}

function markLoaded(img) {
	if (!img.classList.contains('is-loaded')) {
		img.classList.add('is-loaded');
	}
}

/** Photos that loaded before anything listened: from the browser's cache, or before the wiring. */
function markComplete(root) {
	root.querySelectorAll('img[data-photo]').forEach(img => {
		if (img.complete && img.naturalWidth > 0) {
			markLoaded(img);
		}
	});
}

function followStrip(strip) {
	const gallery = strip.closest('[data-gallery]');
	const dots = gallery ? gallery.querySelectorAll('.gallery-dot') : [];
	if (dots.length === 0) {
		return;
	}
	const active = galleryIndex(strip.scrollLeft, strip.clientWidth, dots.length);
	dots.forEach((dot, index) => {
		const on = dot.hasAttribute('data-active');
		if (index === active && !on) {
			dot.setAttribute('data-active', '');
		} else if (index !== active && on) {
			dot.removeAttribute('data-active');
		}
	});
}

/** Wires every gallery in `root`, the ones a morph brings in later too: load and scroll do not bubble, so both listen in the capture phase. */
export function setupGallery(root) {
	root.addEventListener(
		'load',
		event => {
			const img = event.target;
			if (img && img.matches && img.matches('img[data-photo]')) {
				markLoaded(img);
			}
		},
		true,
	);
	root.addEventListener(
		'scroll',
		event => {
			const strip = event.target;
			if (strip && strip.matches && strip.matches('[data-gallery-strip]')) {
				followStrip(strip);
			}
		},
		{capture: true, passive: true},
	);
	markComplete(root);
	onScreenMorphed(target => {
		if (target.contains && target.contains(root)) {
			markComplete(root);
			// a dot is keyed by its photo and keeps data-active, so one the morph moved
			// may no longer be the strip's; the strip's scroll says which is
			root.querySelectorAll('[data-gallery-strip]').forEach(followStrip);
		}
	});
}
