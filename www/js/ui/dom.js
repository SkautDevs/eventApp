/**
 * Writes that touch the DOM only when the value differs. A screen re-asserts its own state
 * after a background morph, and a restore that rewrote every attribute it touches would
 * undo half of what the morph's own minimality is for.
 */

export function setAttr(el, name, value) {
	if (el.getAttribute(name) !== value) {
		el.setAttribute(name, value);
	}
}

export function setText(el, text) {
	if (el.textContent !== text) {
		el.textContent = text;
	}
}

export function setDisabled(el, disabled) {
	if (el.disabled !== disabled) {
		el.disabled = disabled;
	}
}
