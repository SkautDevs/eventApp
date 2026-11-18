/**
 * The morph: a screen is never replaced, only patched into the shape of the fresh one.
 *
 * innerHTML would have been one line, and it would have thrown away exactly the
 * live state this whole design exists to keep: the map's iframe would refetch, the
 * timeline would lose its zoom and its horizontal scroll, a half-typed field would
 * empty. So the two trees are walked together and only what differs is written.
 *
 * It is hand-rolled and deliberately small — no diffing library, no dependency.
 * Three rules carry it:
 *
 *  - children are matched on `id`, then on `data-key`, then by position among
 *    same-tag siblings, so an insertion in the middle of a list moves nothing
 *    after it;
 *  - a subtree that is already equal to its fresh counterpart is skipped outright;
 *  - a matched <iframe> is never written to and never moved (a move re-inserts it,
 *    and an iframe re-inserted is an iframe reloaded), and no matched element is
 *    ever re-created, which is what keeps .tl-scroll's scrollLeft and the focused
 *    element alive.
 *
 * No state and no event: the loader (loader.js) calls it and announces the result.
 */

export function keyOf(node) {
	if (node.nodeType !== 1) {
		return null;
	}

	return node.id || node.getAttribute('data-key') || null;
}

/** Two nodes can stand in for one another only if they are the same kind of thing. */
export function kindOf(node) {
	return node.nodeType === 1 ? 'e:' + node.tagName : 'n:' + node.nodeType;
}

/** Moving a node that owns an iframe reloads it just as surely as replacing it does. */
export function ownsIframe(node) {
	return node.nodeType === 1 && (node.tagName === 'IFRAME' || !!node.querySelector('iframe'));
}

/**
 * Attributes the fresh node states are made to match. Two things are left alone.
 *
 * An attribute the fresh node does not carry at all: the server never writes it,
 * so it is the client's — --hour-width lives in .pg's style attribute,
 * data-hour-step is the ruler's density, data-pg-ready the wiring flag, `disabled`
 * the zoom pair's own bookkeeping.
 *
 * And an attribute the markup itself declares as the client's through
 * `data-morph-keep`, for the ones the server does write because it has to render
 * *some* default: which view .pg is showing, which .tl-page is the active one,
 * which day chip is current.
 * Writing the server's value and putting it back a line later is not free — in
 * between, the screen is briefly a different shape, and a layout landing in that
 * window takes the reader's scroll position with it. The loader knows nothing
 * about which attributes those are; the screen says so.
 */
export function patchAttributes(from, to) {
	let owned = from.getAttribute('data-morph-keep');
	owned = owned === null ? null : ' ' + owned + ' ';
	const attrs = to.attributes;
	for (let i = 0; i < attrs.length; i++) {
		if (owned !== null && owned.indexOf(' ' + attrs[i].name + ' ') >= 0) {
			continue;
		}
		if (from.getAttribute(attrs[i].name) !== attrs[i].value) {
			from.setAttribute(attrs[i].name, attrs[i].value);
		}
	}
}

function patch(from, to) {
	if (from.nodeType !== 1) {
		if (from.nodeValue !== to.nodeValue) {
			from.nodeValue = to.nodeValue;
		}

		return;
	}
	if (from.tagName === 'IFRAME') {
		return;
	}
	// the fast path, and the reason a one-programme change does not walk the grid
	if (from.isEqualNode(to)) {
		return;
	}
	patchAttributes(from, to);
	morphChildren(from, to);
}

export function morphChildren(from, to) {
	const oldNodes = [];
	for (let node = from.firstChild; node; node = node.nextSibling) {
		oldNodes.push(node);
	}
	const keyed = new Map();
	/** unkeyed children queue up per kind, which is "position among same-tag siblings" */
	const pools = new Map();
	oldNodes.forEach(child => {
		const key = keyOf(child);
		if (key !== null) {
			if (!keyed.has(key)) {
				keyed.set(key, child);
			}

			return;
		}
		const kind = kindOf(child);
		if (!pools.has(kind)) {
			pools.set(kind, []);
		}
		pools.get(kind).push(child);
	});

	// match first, move second: matching against a tree that is being reordered
	// under the loop is how a morph starts producing nonsense
	const matched = new Set();
	const plan = [];
	for (let wanted = to.firstChild; wanted; wanted = wanted.nextSibling) {
		const key = keyOf(wanted);
		let match = null;
		if (key !== null) {
			const candidate = keyed.get(key);
			if (candidate && !matched.has(candidate) && kindOf(candidate) === kindOf(wanted)) {
				match = candidate;
			}
		} else {
			const pool = pools.get(kindOf(wanted));
			match = pool && pool.length > 0 ? pool.shift() : null;
		}
		if (match) {
			matched.add(match);
		}
		plan.push({wanted: wanted, match: match});
	}

	let cursor = from.firstChild;
	plan.forEach(step => {
		if (!step.match) {
			from.insertBefore(document.importNode(step.wanted, true), cursor);

			return;
		}
		if (step.match === cursor) {
			cursor = cursor.nextSibling;
		} else if (!ownsIframe(step.match)) {
			from.insertBefore(step.match, cursor);
		} else {
			// The one node that stays where it is: moving it reloads the iframe.
			// The cursor has to step over it all the same, or every later
			// insertBefore aims at a position the pinned node is now behind, and
			// the siblings around it come out in the wrong order.
			cursor = step.match.nextSibling;
		}
		patch(step.match, step.wanted);
	});

	oldNodes.forEach(child => {
		if (!matched.has(child) && child.parentNode === from) {
			from.removeChild(child);
		}
	});
}

/** Focus and the caret survive the morph if the element they were on does. */
export function captureFocus(section) {
	const focused = document.activeElement;
	if (!focused || !section.contains(focused)) {
		return null;
	}
	const state = {element: focused, start: null, end: null};
	try {
		state.start = focused.selectionStart;
		state.end = focused.selectionEnd;
	} catch (_e) {
		// a <button> has no selection to remember
	}

	return state;
}

export function restoreFocus(state) {
	if (!state || !document.contains(state.element) || document.activeElement === state.element) {
		return;
	}
	state.element.focus({preventScroll: true});
	if (state.start !== null) {
		try {
			state.element.setSelectionRange(state.start, state.end);
		} catch (_e) {
			// the field stopped accepting a range: the focus alone is the point
		}
	}
}
