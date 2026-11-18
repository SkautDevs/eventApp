/**
 * Přehled and Moje: one continuous scroll each.
 *
 * The two lists do not page. Every day of each is in the document, first to
 * last, each under its own sticky heading, and the strip on top is an
 * orientation instrument rather than a pager: the current chip follows the
 * scroll, and a tap on a chip scrolls to that day's heading. The behaviour is
 * built per kind — 'overview' and 'list', the kinds whose view holds days.
 *
 * Nothing here runs on a scroll event. An IntersectionObserver watches the
 * day sections against a band that starts at the bottom edge of the strip, so
 * it speaks only when a day crosses that line — a handful of times per scroll
 * gesture — and each crossing costs one coalesced rAF that reads no geometry.
 * The day at the top of the band is the day whose heading is stuck there, so
 * the strip and the heading can never disagree.
 */

export const LIST_KINDS = ['overview', 'list'];

export function isList(view) {
	return LIST_KINDS.indexOf(view) >= 0;
}

export function setup(ctx) {
	const {root} = ctx;
	// per kind: the days crossing the band, the observer, the pending frame, the
	// current day and the window offset the list was left at
	const lists = {};

	ctx.listState = kind => {
		if (!lists[kind]) {
			lists[kind] = {visible: new Set(), spy: null, frame: 0, currentKey: null, scroll: 0};
		}

		return lists[kind];
	};

	// The days themselves are queried per call rather than held in a variable: a
	// background morph can hand the screen a new set of them, and a stale
	// reference to one would quietly stop the strip following the scroll.
	ctx.listDays = kind => Array.from(root.querySelectorAll('.view-' + kind + ' .pl-day'));

	/**
	 * The bottom edge of the strip: below it is the part of the list being read.
	 *
	 * The notch counts. The strip is docked under the app bar and the bar grows by
	 * the top safe-area inset, so on a notched standalone install the band starts
	 * that much further down; without it a tapped chip scrolled a heading 34px behind
	 * the strip it was meant to dock under. --appbar-inset can be read here
	 * even though it is an env(): env(), like var(), is substituted while the
	 * computed value is worked out, so this comes back as a plain length. A calc()
	 * would not — which is exactly why the inset is a token of its own rather than
	 * part of --appbar-height.
	 */
	function bandTop() {
		return ctx.cssPx('--appbar-height') + ctx.cssPx('--appbar-inset') + ctx.cssPx('--pager-height');
	}

	function reducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function scrollWindowTo(top) {
		window.scrollTo({top: Math.max(Math.round(top), 0), behavior: reducedMotion() ? 'auto' : 'smooth'});
	}

	/** Where the window sits when `day`'s heading is docked under the strip. */
	function dayStart(day) {
		return window.scrollY + day.getBoundingClientRect().top - bandTop();
	}

	function watchList(kind) {
		const state = ctx.listState(kind);
		const targets = ctx.listDays(kind);
		if (state.spy || targets.length === 0 || !window.IntersectionObserver) {
			return;
		}
		// One pixel below the strip rather than exactly on it: a day scrolled to its
		// own start puts its top edge and the previous day's bottom edge on the same
		// line, and an observer asked about that line answers "both". A pixel of
		// clearance makes the outgoing day unambiguously outgoing, which is what
		// keeps the label from naming the day above the one whose heading is stuck.
		state.spy = new IntersectionObserver(
			entries => {
				entries.forEach(entry => {
					if (entry.isIntersecting) {
						state.visible.add(entry.target);
					} else {
						state.visible.delete(entry.target);
					}
				});
				if (state.frame) {
					return;
				}
				state.frame = requestAnimationFrame(() => {
					state.frame = 0;
					syncDay(kind);
				});
			},
			{rootMargin: '-' + (Math.round(bandTop()) + 1) + 'px 0px 0px 0px'},
		);
		targets.forEach(day => {
			state.spy.observe(day);
		});
	}

	ctx.unwatchLists = () => {
		LIST_KINDS.forEach(kind => {
			const state = ctx.listState(kind);
			if (state.spy) {
				state.spy.disconnect();
				state.spy = null;
			}
			state.visible.clear();
		});
	};

	/** The day being read is the first one still crossing the band. */
	function syncDay(kind) {
		const state = ctx.listState(kind);
		const current = ctx.listDays(kind).find(day => state.visible.has(day));
		// none crossing means the reader is past the end of the last day, and the
		// last day is still what they are reading
		ctx.setCurrentDay(kind, current ? current.dataset.key : null);
	}

	/** Makes the day `key` current (null keeps the current one) and marks its chip; the first day when there is none. */
	ctx.setCurrentDay = (kind, key) => {
		const state = ctx.listState(kind);
		const targets = ctx.listDays(kind);
		if (key !== null) {
			state.currentKey = key;
		}
		let index = targets.findIndex(item => item.dataset.key === state.currentKey);
		if (index < 0 && targets.length > 0) {
			index = 0;
			state.currentKey = targets[0].dataset.key;
		}

		if (index >= 0) {
			ctx.markChip(kind, targets[index].dataset.key);
		}
	};

	ctx.scrollToDay = (kind, key) => {
		const day = ctx.listDays(kind).find(item => item.dataset.key === key);
		if (!day) {
			return;
		}
		scrollWindowTo(dayStart(day));
		ctx.setCurrentDay(kind, day.dataset.key);
	};

	/** Puts a single programme of a list under the strip, clear of its day's stuck heading. */
	ctx.scrollToItem = (kind, id) => {
		const item = root.querySelector('.view-' + kind + ' .pl-item[data-key="' + id + '"]');
		if (!item) {
			return false;
		}
		const day = item.closest('.pl-day');
		const head = day.querySelector('.pl-head');
		// clear of the day's stuck heading, but never so high that the heading
		// itself loses the band and the strip starts naming the day above it
		scrollWindowTo(
			Math.max(window.scrollY + item.getBoundingClientRect().top - bandTop() - (head ? head.getBoundingClientRect().height : 0) - 8, dayStart(day)),
		);

		return true;
	};

	/**
	 * The timeline is exactly one screenful, so while it is up the document is
	 * too short to hold a list's scroll and the browser clamps it to 0; and the
	 * two lists are different documents' worth of height. Remembering each list's
	 * offset when the reader leaves it is what makes switching views and coming
	 * back land where the reader was, the same way leaving the screen does.
	 */
	ctx.leaveList = kind => {
		ctx.listState(kind).scroll = window.scrollY;
	};

	ctx.enterList = (kind, restore) => {
		watchList(kind);
		if (kind === 'list' && ctx.pendingListTarget !== null) {
			const found = ctx.scrollToItem('list', ctx.pendingListTarget);
			ctx.pendingListTarget = null;
			if (found) {
				return;
			}
		}
		// the browser clamped the window while the timeline was up, and the other
		// list left it wherever that one was read
		const saved = ctx.listState(kind).scroll;
		if (restore && window.scrollY !== saved) {
			window.scrollTo(0, saved);
		}
		syncDay(kind);
	};
}
