/** The day strips: which chip is current, and the strip scrolled sideways to show it. */

import {setAttr} from '../ui/dom.js';
import {chipScroll} from './maths.js';

export function setup(ctx) {
	const {root} = ctx;

	/**
	 * Marks the chip for `key` current in `kind`'s strip and brings it into the
	 * strip's view. Only the strip scrolls sideways — never scrollIntoView, which
	 * would also move the window.
	 */
	ctx.markChip = (kind, key) => {
		const strip = root.querySelector('[data-pg-days="' + kind + '"]');
		if (!strip) {
			return;
		}
		let current = null;
		strip.querySelectorAll('.day-chip').forEach(chip => {
			const on = chip.dataset.pgPage === key;
			setAttr(chip, 'aria-current', on ? 'true' : 'false');
			if (on) {
				current = chip;
			}
		});
		// a strip inside a hidden view measures nothing; it is brought round when shown
		if (!current || strip.clientWidth === 0) {
			return;
		}
		// .days is position: relative, so offsetLeft is already inside the strip
		const left = chipScroll(current.offsetLeft, current.offsetWidth, strip.scrollLeft, strip.clientWidth);
		if (left !== null) {
			strip.scrollLeft = left;
		}
	};
}
