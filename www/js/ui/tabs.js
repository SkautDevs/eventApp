/** A tab strip as the tablist pattern expects it: arrow keys walk it, the selected tab alone is in the tab order. */

import {setAttr} from './dom.js';

/** The index the key moves to from `here`: ArrowLeft and ArrowRight wrap, Home is the first, End the last; null for any other key. */
export function nextTabIndex(key, here, length) {
	if (key === 'ArrowLeft') {
		return (here - 1 + length) % length;
	}
	if (key === 'ArrowRight') {
		return (here + 1) % length;
	}
	if (key === 'Home') {
		return 0;
	}
	if (key === 'End') {
		return length - 1;
	}

	return null;
}

/**
 * aria-selected "true"/"false", written when it differs; the roving tabindex 0 or -1,
 * written when it differs, or every time with alwaysWriteTabIndex (as Místa does).
 */
export function markSelected(tab, selected, alwaysWriteTabIndex) {
	setAttr(tab, 'aria-selected', String(selected));
	const index = selected ? 0 : -1;
	if (alwaysWriteTabIndex || tab.tabIndex !== index) {
		tab.tabIndex = index;
	}
}
