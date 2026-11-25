/**
 * The programme sheet, the screen's one dialog: opening and closing it, its focus trap,
 * and the history entry that lets Back close it. The three share the dialog's state.
 *
 * The open sheet has an address: its entry is the view's path with the body's id as the
 * hash (`/programy/moje#section-<s>-program-<id>`). Back and Forward onto such an entry
 * open that sheet again, onto any other entry close it; a reload of it reopens it.
 */

import {emitSheetClosed, onScreenHidden} from '../events.js';
import {hashTarget, legacyTarget} from './maths.js';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function setup(ctx) {
	const {root} = ctx;

	// Looked up on every use: a background morph may have replaced or moved them, and a
	// reference held from the first render is how a tap once opened a day panel.
	function sheetParts() {
		return {
			sheet: root.querySelector('[data-pg-sheet]'),
			card: root.querySelector('[data-pg-sheet-card]'),
			scroll: root.querySelector('[data-pg-sheet-scroll]'),
		};
	}
	// where focus goes back to when the sheet closes
	let opener = null;

	// --- the open dialog ---------------------------------------------
	// The sheet is modal to the pointer and to the accessibility
	// tree — a backdrop over the screen, aria-modal on the card — but they were
	// never modal to Tab: the app bar, the pager, every .pl-open behind the dimming,
	// the view tabs and the tab bar all stayed in the tab order, with .sheet-close
	// somewhere in the middle of them. `inert` takes the rest of the document out of
	// it, and the Tab handler below closes the ring inside the card.

	// exactly what the trap made inert, so that closing clears that and not an
	// `inert` somebody else put there
	let inerted = [];
	let trapCard = null;

	function trapFocus(dialog, card) {
		releaseFocus();
		trapCard = card;
		// everything beside the dialog on its way up to <body>: the rest of the
		// screen, the screens the loader is keeping alive next to it, and the
		// shell's own skip link, app bar, live region and tab bar with them — but
		// never a toast
		for (let node = dialog; node && node !== document.body && node.parentElement; node = node.parentElement) {
			Array.prototype.forEach.call(node.parentElement.children, sibling => {
				// A toast stays live: it is what the redirect after a register or an
				// unregister says, painted over the sheet that deep link reopens, and
				// its ✕ has to work with the sheet open (www/js/shell/toast.js).
				if (sibling !== node && !sibling.inert && !sibling.hasAttribute('data-toast')) {
					sibling.inert = true;
					inerted.push(sibling);
				}
			});
		}
	}

	function releaseFocus() {
		inerted.forEach(element => {
			element.inert = false;
		});
		inerted = [];
		trapCard = null;
	}

	/** Whether the open sheet holds the focus ring. */
	ctx.trapped = () => !!trapCard;

	/**
	 * Tab wraps inside the card. With the rest of the document inert the browser
	 * would otherwise leave through its own chrome and come back in at the top; the
	 * ring keeps the reader inside the dialog, which is what aria-modal promises.
	 */
	ctx.cycleTab = event => {
		// A toast over the open sheet (what a register said, on the sheet its redirect
		// reopened) is live and not inert, so its close button is part of the ring —
		// first, as it is first on the screen. Without it a keyboard could never reach it.
		const toasts = Array.from(document.querySelectorAll('[data-toast] [data-toast-close]'));
		const items = toasts.concat(Array.from(trapCard.querySelectorAll(FOCUSABLE))).filter(
			// the sheet holds every programme's detail and shows one, so the links
			// in all the others are inside the card but not on the screen; a toast on
			// another screen is hidden with it, and one inside an inert part is out of reach
			item => item.getClientRects().length > 0 && !item.closest('[inert]'),
		);
		const here = document.activeElement;
		const inside = trapCard.contains(here) || items.indexOf(here) >= 0;
		if (items.length === 0) {
			event.preventDefault();
			trapCard.focus();

			return;
		}
		const first = items[0];
		const last = items[items.length - 1];
		if (event.shiftKey ? !inside || here === first : !inside || here === last) {
			event.preventDefault();
			(event.shiftKey ? last : first).focus();
		}
	};

	// --- Back closes the open dialog ------------------------------------
	// Opening the sheet pushes a history entry, so Android's Back (and
	// the browser's) closes it instead of leaving the screen with every other part of
	// the app still inert. Closing it any other way goes back over that entry once.
	//
	// Chrome's history-manipulation intervention may let the browser's own Back skip
	// an entry pushed without a user gesture — the sheet a deep link opens on landing
	// is one. The entry is then no guarantee; the popstate and screen:hidden listeners
	// below are: whatever entry Back lands on, and whatever screen is hidden, the
	// dialog closes and the trap lets go. Such a path can leave an orphan overlay
	// entry behind, which costs one extra Back that does nothing, and is accepted.
	let leaving = false;
	let afterLeave = null;
	// The browser restores the scroll it saved for an entry when Back lands on it —
	// after popstate has run, so anything the screen scrolled after a close would be
	// scrolled straight back to where the sheet was opened. While an overlay entry is up the screen owns
	// its scroll instead. The mode belongs to each entry and a pushed entry copies the
	// current one's, so it is set before the push (covering both entries) and handed
	// back only once the traversal has finished restoring nothing.
	let savedRestoration = null;

	/**
	 * The sheet's entry, with `hash` (the body's id) on the address. An overlay entry already
	 * up (a sheet open, a reload of one, Back or Forward onto one) is rewritten in place.
	 * Otherwise the entry under the sheet is first stripped of a programme hash it carries
	 * (a landing on a deep link, an old hash rewritten): Back onto it would open the sheet
	 * again. Then the sheet's entry is pushed.
	 */
	function enterOverlay(hash) {
		if (savedRestoration === null && 'scrollRestoration' in history) {
			savedRestoration = history.scrollRestoration;
			history.scrollRestoration = 'manual';
		}
		const address = location.pathname + location.search;
		if (history.state && history.state.pgOverlay) {
			history.replaceState(history.state, '', address + hash);

			return;
		}
		if (hashTarget(location.hash) !== null) {
			history.replaceState(history.state, '', address);
		}
		history.pushState({...history.state, screen: ctx.screen, pgOverlay: true}, '', address + hash);
	}

	function restoreScrollRestoration() {
		if (savedRestoration === null) {
			return;
		}
		const mode = savedRestoration;
		savedRestoration = null;
		// a task later: the traversal's own scroll restoration comes after popstate
		window.setTimeout(() => {
			if (savedRestoration === null) {
				history.scrollRestoration = mode;
			}
		}, 0);
	}

	/** Goes back over the dialog's entry; `then` runs once the traversal has landed. */
	function leaveOverlay(then) {
		if (!leaving && history.state && history.state.pgOverlay) {
			leaving = true;
			afterLeave = then || null;
			history.back();
		} else if (then) {
			then();
		}
	}

	/**
	 * A close's history.back() is still in flight: its popstate has not landed yet,
	 * and when it does it closes whatever is open. An open asked for in that gap
	 * waits for the traversal, then pushes its own entry on the base one.
	 */
	function deferWhileLeaving(open) {
		if (!leaving) {
			return false;
		}
		const before = afterLeave;
		afterLeave = () => {
			if (before) {
				before();
			}
			open();
		};

		return true;
	}

	ctx.openSheet = (id, followPage, trigger) => {
		if (
			deferWhileLeaving(() => {
				ctx.openSheet(id, followPage, trigger);
			})
		) {
			return;
		}
		const body = root.querySelector('[data-pg-detail="' + id + '"]');
		const parts = sheetParts();
		if (!body || !parts.sheet || !parts.card) {
			return;
		}
		root.querySelectorAll('[data-pg-detail]').forEach(other => {
			other.classList.remove('is-open');
		});
		body.classList.add('is-open');
		// the dialog is named by the programme it shows, not by a constant
		parts.card.setAttribute('aria-labelledby', 'sheet-name-' + id);
		parts.card.removeAttribute('aria-label');
		// the path decides the view; on Harmonogram the timeline goes to the programme's page
		if (followPage && body.dataset.page) {
			ctx.showPageByKey('timeline', body.dataset.page);
			// and the personal list is put on the same programme, so that opening
			// Můj program after a deep link lands on it rather than at the top
			ctx.pendingListTarget = id;
		}
		opener = trigger || null;
		parts.sheet.classList.add('is-open');
		enterOverlay('#' + body.id);
		if (parts.scroll) {
			parts.scroll.scrollTop = 0;
		}
		trapFocus(parts.sheet, parts.card);
		parts.card.focus();
	};

	ctx.closeSheet = fromHistory => {
		const parts = sheetParts();
		if (!parts.sheet || !parts.sheet.classList.contains('is-open')) {
			return;
		}
		parts.sheet.classList.remove('is-open');
		// the shell's two-tap confirmation withdraws a question the sheet's unregister button was asking
		emitSheetClosed(parts.sheet);
		// before the focus goes back: an inert element cannot take it
		releaseFocus();
		const back = opener && document.contains(opener) ? opener : fallbackFocus();
		if (back) {
			back.focus();
		}
		opener = null;
		if (!fromHistory) {
			leaveOverlay();
		}
	};

	/**
	 * Where focus goes when the sheet closes with no opener (it opened on landing, on Back
	 * or on a show of the screen): the open programme's link on the view on show, else its
	 * row, else the screen's section (tabindex="-1", so focusable but never tabbed to).
	 * Never <body>, where a screen reader loses its place.
	 */
	function fallbackFocus() {
		const body = root.querySelector('[data-pg-detail].is-open');
		const rows = body ? root.querySelectorAll('[data-pg-open="' + body.getAttribute('data-pg-detail') + '"]') : [];
		const shown = Array.prototype.filter.call(rows, el => el.getClientRects().length > 0);

		return shown.find(el => el.matches('a[href]')) || shown[0] || root.closest('[data-screen]');
	}

	/** Whether programme `id`'s sheet is the one open. */
	function showing(id) {
		const parts = sheetParts();
		const body = root.querySelector('[data-pg-detail="' + id + '"]');

		return !!(parts.sheet && body && parts.sheet.classList.contains('is-open') && body.classList.contains('is-open'));
	}

	// Back (or Forward) on any entry, and a same-document hash change (a notification's
	// client.navigate() to the screen already on show): an entry whose hash names a
	// programme of this screen, or is an old Moje hash, is read as the wiring reads it
	// (ctx.fromHash(): the timeline's page, Moje's target, the old hash rewritten), and the
	// sheet opens on that very entry (enterOverlay() rewrites an overlay entry rather than
	// pushing). Any other entry closes whatever is open and lets the trap go — also the
	// backstop for an entry this screen did not push. Only while the screen is on show: on
	// a Back from another screen its screen:shown (ctx.syncFromUrl) reads the address, and a
	// sheet that already shows the programme is left alone, so it opens once.
	window.addEventListener('popstate', () => {
		leaving = false;
		const target = hashTarget(location.hash);
		const visible = document.contains(root) && !root.closest('[data-screen][hidden]');
		const named = target !== null && root.querySelector('[data-pg-detail="' + target.id + '"]') !== null;
		if (visible && (named || legacyTarget(location.hash) !== null)) {
			if (!named || !showing(target.id)) {
				ctx.fromHash();
			}
		} else {
			ctx.closeSheet(true);
		}
		// an open sheet keeps owning the scroll; a closed one hands it back
		if (!ctx.trapped()) {
			restoreScrollRestoration();
		}
		const run = afterLeave;
		afterLeave = null;
		if (run) {
			run();
		}
	});

	onScreenHidden(target => {
		if (target.contains && target.contains(root)) {
			// nothing deferred may open on a screen nobody is looking at
			afterLeave = null;
			ctx.closeSheet(true);
			restoreScrollRestoration();
		}
	});
}
