/**
 * The Program screen's behaviour.
 *
 * This used to be an inline <script> in programs.twig's {% block header %}, i.e. in
 * <head> — a place no screen swap can reach. It is a file now, loaded once by the
 * shell, and window.pgInit(scope) wires whatever Program screen is inside `scope`.
 *
 * The entry point is idempotent: it marks the root it has wired and returns early on
 * a second call, so the loader can call it on every show without thinking about it.
 * It must be called only once the screen is *visible* — the ruler's label-width
 * measurement and every scrollLeft calculation return 0 inside a hidden subtree.
 *
 * The whole screen is one page: every day's timeline page, both views and every
 * programme detail are in the DOM, and this only decides what is visible.
 */
(function () {
	'use strict';

	function init(root) {
		if (!root || root.dataset.pgReady === '1') {
			return;
		}
		root.dataset.pgReady = '1';

		const sheet = root.querySelector('[data-pg-sheet]');
		const sheetCard = root.querySelector('[data-pg-sheet-card]');
		const sheetScroll = root.querySelector('[data-pg-sheet-scroll]');
		// where focus goes back to when the sheet, and the day panel, close
		let opener = null;
		let menuOpener = null;
		// What the reader owns rather than the server: which view is up and which page
		// the timeline is on. Held here as well as in the DOM, because a background
		// morph rewrites the DOM back to the server's defaults and this is what puts
		// the reader's own state back on top of it.
		let currentView = root.dataset.view || 'timeline';
		let currentPageKey = null;

		// Write only what differs. The screen re-asserts its own state after a
		// background morph, and a restore that rewrote every attribute it touches
		// would undo half of what the morph's own minimality is for.
		function setAttr(element, name, value) {
			if (element.getAttribute(name) !== value) {
				element.setAttribute(name, value);
			}
		}

		function setText(element, text) {
			if (element.textContent !== text) {
				element.textContent = text;
			}
		}

		function setDisabled(element, disabled) {
			if (element.disabled !== disabled) {
				element.disabled = disabled;
			}
		}

		function panels(kind) {
			return Array.from(root.querySelectorAll('[data-pg-panel="' + kind + '"]'));
		}

		function activeIndex(kind) {
			return panels(kind).findIndex(function (panel) {
				return panel.classList.contains('is-active');
			});
		}

		function showPage(kind, index) {
			const list = panels(kind);
			if (list.length === 0) {
				return;
			}
			index = Math.min(Math.max(index, 0), list.length - 1);
			// the page transition runs the other way round when stepping backwards
			const previous = activeIndex(kind);
			if (previous >= 0 && index !== previous) {
				root.dataset.step = index < previous ? 'prev' : 'next';
			}
			list.forEach(function (panel, i) {
				panel.classList.toggle('is-active', i === index);
			});

			currentPageKey = list[index].dataset.key;
			const title = root.querySelector('[data-pg-title="' + kind + '"]');
			if (title) {
				setText(title, list[index].dataset.label);
			}
			root.querySelectorAll('[data-pg-page][data-pg-kind="' + kind + '"]').forEach(function (item) {
				item.classList.toggle('is-active', item.dataset.pgPage === list[index].dataset.key);
			});
			root.querySelectorAll('[data-pg-step][data-pg-kind="' + kind + '"]').forEach(function (arrow) {
				const target = index + Number(arrow.dataset.pgStep);
				setDisabled(arrow, target < 0 || target > list.length - 1);
			});
			// the ruler that is on screen is this page's, so re-check its density
			applyTickStep();
		}

		function showPageByKey(kind, key) {
			const index = panels(kind).findIndex(function (panel) {
				return panel.dataset.key === key;
			});
			if (index >= 0) {
				showPage(kind, index);
			}
		}

		function tabs() {
			return Array.from(root.querySelectorAll('[data-pg-view]'));
		}

		function showView(view) {
			// The timeline is exactly one screenful, so while it is up the document is
			// too short to hold the list's scroll and the browser clamps it to 0.
			// Remembering it here is what makes switching views and coming back land
			// where the reader was, the same way leaving the screen does.
			const wasList = currentView === 'list';
			if (wasList && view !== 'list') {
				listScroll = window.scrollY;
			}
			currentView = view;
			if (root.dataset.view !== view) {
				root.dataset.view = view;
			}
			tabs().forEach(function (tab) {
				const active = tab.dataset.pgView === view;
				setAttr(tab, 'aria-selected', active ? 'true' : 'false');
				// roving tabindex: only the selected tab is in the tab order
				if (tab.tabIndex !== (active ? 0 : -1)) {
					tab.tabIndex = active ? 0 : -1;
				}
			});
			closeMenus();
			if (view === 'list') {
				// The saved offset is only written when the reader leaves the list, so
				// it is older than where they are as soon as they scroll again. It is
				// therefore restored on a genuine switch into the list and nowhere
				// else: showView('list') also runs after a background morph, with the
				// reader sitting in the list — and possibly on another screen
				// entirely, whose scroll it would be the one to move.
				enterList(!wasList);
			}
		}

		// --- the open dialog ---------------------------------------------
		// The sheet and the day panel are modal to the pointer and to the accessibility
		// tree — a backdrop over the screen, aria-modal on the card — but they were
		// never modal to Tab: the app bar, the pager, every .pl-open behind the dimming,
		// the view tabs and the tab bar all stayed in the tab order, with .sheet-close
		// somewhere in the middle of them. `inert` takes the rest of the document out of
		// it, and the Tab handler below closes the ring inside the card.

		const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]),'
			+ ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		// exactly what the trap made inert, so that closing clears that and not an
		// `inert` somebody else put there
		let inerted = [];
		let trapCard = null;

		function trapFocus(dialog, card) {
			releaseFocus();
			trapCard = card;
			// everything beside the dialog on its way up to <body>: the rest of the
			// screen, the screens the loader is keeping alive next to it, and the
			// shell's own skip link, app bar, live region and tab bar with them
			for (let node = dialog; node && node !== document.body && node.parentElement; node = node.parentElement) {
				Array.prototype.forEach.call(node.parentElement.children, function (sibling) {
					if (sibling !== node && !sibling.inert) {
						sibling.inert = true;
						inerted.push(sibling);
					}
				});
			}
		}

		function releaseFocus() {
			inerted.forEach(function (element) {
				element.inert = false;
			});
			inerted = [];
			trapCard = null;
		}

		/**
		 * Tab wraps inside the card. With the rest of the document inert the browser
		 * would otherwise leave through its own chrome and come back in at the top; the
		 * ring keeps the reader inside the dialog, which is what aria-modal promises.
		 */
		function cycleTab(event) {
			const items = Array.from(trapCard.querySelectorAll(FOCUSABLE)).filter(function (item) {
				// the sheet holds every programme's detail and shows one, so the links
				// in all the others are inside the card but not on the screen
				return item.getClientRects().length > 0;
			});
			const here = document.activeElement;
			const inside = trapCard.contains(here);
			if (items.length === 0) {
				event.preventDefault();
				trapCard.focus();

				return;
			}
			const first = items[0];
			const last = items[items.length - 1];
			if (event.shiftKey ? (!inside || here === first) : (!inside || here === last)) {
				event.preventDefault();
				(event.shiftKey ? last : first).focus();
			}
		}

		function closeMenus() {
			let wasOpen = false;
			root.querySelectorAll('[data-pg-menu-panel]').forEach(function (menu) {
				if (!menu.classList.contains('is-open')) {
					return;
				}
				wasOpen = true;
				menu.classList.remove('is-open');
				menu.setAttribute('aria-hidden', 'true');
			});
			root.querySelectorAll('[data-pg-menu]').forEach(function (button) {
				setAttr(button, 'aria-expanded', 'false');
			});
			// the day panel returns focus to its trigger, exactly as the sheet does —
			// after the trap is lifted, because focus() on an inert element does nothing
			if (wasOpen) {
				releaseFocus();
			}
			if (wasOpen && menuOpener && document.contains(menuOpener)) {
				menuOpener.focus();
			}
			menuOpener = null;
		}

		function openMenu(kind, trigger) {
			const menu = root.querySelector('[data-pg-menu-panel="' + kind + '"]');
			if (!menu) {
				return;
			}
			closeMenus();
			menu.classList.add('is-open');
			menu.setAttribute('aria-hidden', 'false');
			trigger.setAttribute('aria-expanded', 'true');
			menuOpener = trigger;
			const card = menu.querySelector('[data-pg-menu-card]');
			if (card) {
				trapFocus(menu, card);
				card.focus();
			}
		}

		function openSheet(id, followPage, trigger) {
			const body = root.querySelector('[data-pg-detail="' + id + '"]');
			if (!body) {
				return;
			}
			root.querySelectorAll('[data-pg-detail]').forEach(function (other) {
				other.classList.remove('is-open');
			});
			body.classList.add('is-open');
			if (followPage && body.dataset.page) {
				showView('timeline');
				showPageByKey('timeline', body.dataset.page);
				// and the personal list is put on the same programme, so that opening
				// Můj program after a deep link lands on it rather than at the top
				pendingListTarget = id;
			}
			closeMenus();
			opener = trigger || null;
			sheet.classList.add('is-open');
			sheet.setAttribute('aria-hidden', 'false');
			sheetScroll.scrollTop = 0;
			trapFocus(sheet, sheetCard);
			sheetCard.focus();
		}

		function closeSheet() {
			if (!sheet.classList.contains('is-open')) {
				return;
			}
			sheet.classList.remove('is-open');
			sheet.setAttribute('aria-hidden', 'true');
			// before the focus goes back: an inert element cannot take it
			releaseFocus();
			if (opener && document.contains(opener)) {
				opener.focus();
			}
			opener = null;
		}

		// --- hour-scale zoom ---------------------------------------------
		// Only --hour-width moves. Every position and width on the grid is
		// already a multiple of it, so setting the property IS the zoom, and
		// row heights, card heights, type sizes and the stage column stay put.
		// transform: scaleX() would have been the cheap way to do this and
		// would have stretched every programme name along with the axis.
		const zoomKey = 'pgZoom:' + (root.dataset.pgEvent || '');
		const cssPx = function (name) {
			return parseFloat(getComputedStyle(root).getPropertyValue(name)) || 0;
		};
		// the bounds live in the stylesheet, next to the property they bound
		const zoomMin = cssPx('--hour-width-min');
		const zoomMax = cssPx('--hour-width-max');
		const stageWidth = cssPx('--tl-stage-width');
		let hourWidth = cssPx('--hour-width');

		function activeScroll() {
			return root.querySelector('.tl-page.is-active .tl-scroll') || root.querySelector('.tl-scroll');
		}

		function clampZoom(value) {
			return Math.min(Math.max(value, zoomMin), zoomMax);
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
				root.querySelectorAll('.tl-page.is-active .tl-tick-label').forEach(function (label) {
					widest = Math.max(widest, label.getBoundingClientRect().width);
				});
				// 5px is .tl-tick's own padding before the text; 14px is the gap
				// that keeps two labels clearly apart rather than merely not
				// touching. That lands the threshold at 61px for Montserrat and
				// 54px for themix — so both events still label every hour at the
				// 64px step and both thin at the 51px one below it, which is
				// where a label and its neighbour come within 5px of colliding.
				if (widest > 0) {
					tickReach = widest + 19;
				}
			}
			return tickReach || 64;
		}

		// Below the threshold only every second hour keeps its text. One step is
		// enough: at the 40px floor that leaves 80px between labels, twice what
		// a label needs, so a third step would thin the axis for no gain.
		function applyTickStep() {
			const step = hourWidth >= tickLabelReach() ? '1' : '2';
			if (root.dataset.hourStep !== step) {
				root.dataset.hourStep = step;
			}
		}

		function syncZoomButtons() {
			root.querySelectorAll('[data-pg-zoom]').forEach(function (button) {
				setDisabled(button, button.dataset.pgZoom === 'in'
					? hourWidth >= zoomMax - 0.01
					: hourWidth <= zoomMin + 0.01);
			});
		}

		/**
		 * Rescale the hour, keeping the moment under anchorClientX where it is.
		 * Without that the grid leaps sideways on every step: the anchor's
		 * distance into the time axis grows with the scale, so scrollLeft has
		 * to grow with it. Omit the anchor to hold the centre of the grid.
		 */
		function setZoom(next, anchorClientX) {
			next = clampZoom(next);
			const box = activeScroll();
			let anchor = 0;
			let timeX = 0;
			if (box) {
				const rect = box.getBoundingClientRect();
				anchor = anchorClientX === undefined ? rect.width / 2 : anchorClientX - rect.left;
				// the stage column is pinned over the axis, so it is not part of it
				timeX = Math.max(box.scrollLeft + anchor - stageWidth, 0);
				timeX *= next / hourWidth;
			}
			hourWidth = next;
			root.style.setProperty('--hour-width', next + 'px');
			if (box) {
				// assigning scrollLeft flushes the layout the new width implies first
				box.scrollLeft = Math.max(stageWidth + timeX - anchor, 0);
			}
			try {
				sessionStorage.setItem(zoomKey, String(next));
			} catch (e) {
				// private browsing: the scale simply does not outlive the page
			}
			syncZoomButtons();
			applyTickStep();
		}

		function restoreZoom() {
			let stored = null;
			try {
				stored = sessionStorage.getItem(zoomKey);
			} catch (e) {
				stored = null;
			}
			const value = parseFloat(stored);
			if (isFinite(value)) {
				hourWidth = clampZoom(value);
				root.style.setProperty('--hour-width', hourWidth + 'px');
			}
			syncZoomButtons();
			applyTickStep();
		}

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

		function requestZoom(next, anchorClientX) {
			zoomWanted = {next: next, anchor: anchorClientX};
			if (zoomFrame) {
				return;
			}
			zoomFrame = requestAnimationFrame(function () {
				zoomFrame = 0;
				const wanted = zoomWanted;
				zoomWanted = null;
				setZoom(wanted.next, wanted.anchor);
			});
		}

		// two fingers on the grid scale the axis, anchored between them
		const pointers = new Map();
		let pinch = null;

		function pinchSpan() {
			const list = Array.from(pointers.values());
			if (list.length < 2) {
				return null;
			}
			const a = list[0];
			const b = list[1];
			return {dist: Math.hypot(a.x - b.x, a.y - b.y), midX: (a.x + b.x) / 2};
		}

		root.addEventListener('pointerdown', function (event) {
			if (event.pointerType !== 'touch' || !event.target.closest('.tl-scroll')) {
				return;
			}
			pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
			const span = pinchSpan();
			if (span && span.dist > 0) {
				pinch = {dist: span.dist, hourWidth: hourWidth};
			}
		});

		root.addEventListener('pointermove', function (event) {
			if (!pointers.has(event.pointerId)) {
				return;
			}
			pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
			const span = pinchSpan();
			if (!pinch || !span || span.dist === 0) {
				return;
			}
			// the box may also have panned under the gesture; setting scrollLeft
			// from the live midpoint each frame is what keeps the anchor honest
			requestZoom(pinch.hourWidth * (span.dist / pinch.dist), span.midX);
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
		root.addEventListener('wheel', function (event) {
			if (!event.ctrlKey || !event.target.closest('.tl-scroll')) {
				return;
			}
			event.preventDefault();
			setZoom(hourWidth * (event.deltaY < 0 ? 1.1 : 1 / 1.1), event.clientX);
		}, {passive: false});

		// --- Můj program: one continuous scroll --------------------------
		// The personal list does not page. Every day of it is in the document, first
		// to last, each under its own sticky heading, and the strip on top is an
		// orientation instrument rather than a pager: the label follows the scroll,
		// the arrows jump between day headings.
		//
		// Nothing here runs on a scroll event. An IntersectionObserver watches the
		// day sections against a band that starts at the bottom edge of the strip, so
		// it speaks only when a day crosses that line — a handful of times per scroll
		// gesture — and each crossing costs one coalesced rAF that reads no geometry.
		// The day at the top of the band is the day whose heading is stuck there, so
		// the strip and the heading can never disagree.

		// The days themselves are queried per call rather than held in a variable: a
		// background morph can hand the screen a new set of them, and a stale
		// reference to one would quietly stop the strip following the scroll.
		const visibleDays = new Set();
		let listSpy = null;
		let spyFrame = 0;
		let atListTop = true;
		let currentDayKey = null;
		let listScroll = 0;
		// a deep link opens the timeline; the list is put on the same programme so
		// that switching to it lands on the programme rather than at the top
		let pendingListTarget = null;

		function listDays() {
			return Array.from(root.querySelectorAll('.pl-day'));
		}

		/**
		 * The bottom edge of the strip: below it is the part of the list being read.
		 *
		 * The notch counts. The strip is docked under the app bar and the bar grows by
		 * the top safe-area inset, so on a notched standalone install the band starts
		 * that much further down; without it the arrows scrolled a heading 34px behind
		 * the strip they were meant to dock it under. --appbar-inset can be read here
		 * even though it is an env(): env(), like var(), is substituted while the
		 * computed value is worked out, so this comes back as a plain length. A calc()
		 * would not — which is exactly why the inset is a token of its own rather than
		 * part of --appbar-height.
		 */
		function bandTop() {
			return cssPx('--appbar-height') + cssPx('--appbar-inset') + cssPx('--pager-height');
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

		function onCross(entries) {
			entries.forEach(function (entry) {
				if (entry.target.hasAttribute('data-pg-list-top')) {
					atListTop = entry.isIntersecting;

					return;
				}
				if (entry.isIntersecting) {
					visibleDays.add(entry.target);
				} else {
					visibleDays.delete(entry.target);
				}
			});
			if (spyFrame) {
				return;
			}
			spyFrame = requestAnimationFrame(function () {
				spyFrame = 0;
				syncDay();
			});
		}

		function watchList() {
			const targets = listDays();
			if (listSpy || targets.length === 0 || !window.IntersectionObserver) {
				return;
			}
			// One pixel below the strip rather than exactly on it: a day scrolled to its
			// own start puts its top edge and the previous day's bottom edge on the same
			// line, and an observer asked about that line answers "both". A pixel of
			// clearance makes the outgoing day unambiguously outgoing, which is what
			// keeps the label from naming the day above the one whose heading is stuck.
			listSpy = new IntersectionObserver(onCross, {rootMargin: '-' + (Math.round(bandTop()) + 1) + 'px 0px 0px 0px'});
			targets.forEach(function (day) {
				listSpy.observe(day);
			});
			const top = root.querySelector('[data-pg-list-top]');
			if (top) {
				listSpy.observe(top);
			}
		}

		/** The day being read is the first one still crossing the band. */
		function syncDay() {
			const targets = listDays();
			const current = targets.find(function (day) {
				return visibleDays.has(day);
			});
			// none crossing means the reader is past the end of the last day, and the
			// last day is still what they are reading
			setCurrentDay(current || null);
		}

		function setCurrentDay(day) {
			const targets = listDays();
			if (day) {
				currentDayKey = day.dataset.key;
			}
			let index = targets.findIndex(function (item) {
				return item.dataset.key === currentDayKey;
			});
			if (index < 0 && targets.length > 0) {
				index = 0;
				currentDayKey = targets[0].dataset.key;
			}

			const title = root.querySelector('[data-pg-title="list"]');
			if (title && index >= 0) {
				setText(title, targets[index].dataset.label);
			}
			root.querySelectorAll('[data-pg-page][data-pg-kind="list"]').forEach(function (item) {
				item.classList.toggle('is-active', item.dataset.pgPage === currentDayKey);
			});
			root.querySelectorAll('[data-pg-step][data-pg-kind="list"]').forEach(function (arrow) {
				// up has nowhere to go at the very top of the scroll, down none at the
				// last day — the ends of the list are visible rather than silent
				setDisabled(arrow, Number(arrow.dataset.pgStep) < 0
					? atListTop
					: index < 0 || index >= targets.length - 1);
			});
		}

		/**
		 * Up goes to the start of the day being read, and only to the previous day if
		 * the reader is already standing on that start — the same rule a music player
		 * uses for "previous track". Down always goes to the next day's start.
		 */
		function stepDay(direction) {
			const targets = listDays();
			if (targets.length === 0) {
				return;
			}
			let index = targets.findIndex(function (item) {
				return item.dataset.key === currentDayKey;
			});
			if (index < 0) {
				index = 0;
			}
			let target;
			if (direction > 0) {
				target = targets[Math.min(index + 1, targets.length - 1)];
			} else {
				target = Math.abs(window.scrollY - dayStart(targets[index])) <= 2
					? targets[Math.max(index - 1, 0)]
					: targets[index];
			}
			scrollWindowTo(dayStart(target));
			setCurrentDay(target);
		}

		function scrollToDay(key) {
			const day = listDays().find(function (item) {
				return item.dataset.key === key;
			});
			if (!day) {
				return;
			}
			scrollWindowTo(dayStart(day));
			setCurrentDay(day);
		}

		/** Puts a single programme under the strip, clear of its day's stuck heading. */
		function scrollToItem(id) {
			const item = root.querySelector('.view-list .pl-item[data-key="' + id + '"]');
			if (!item) {
				return false;
			}
			const day = item.closest('.pl-day');
			const head = day.querySelector('.pl-head');
			// clear of the day's stuck heading, but never so high that the heading
			// itself loses the band and the strip starts naming the day above it
			scrollWindowTo(Math.max(
				window.scrollY + item.getBoundingClientRect().top
					- bandTop() - (head ? head.getBoundingClientRect().height : 0) - 8,
				dayStart(day),
			));

			return true;
		}

		function enterList(restore) {
			watchList();
			if (pendingListTarget !== null) {
				const found = scrollToItem(pendingListTarget);
				pendingListTarget = null;
				if (found) {
					return;
				}
			}
			// the browser clamped the window while the timeline was up
			if (restore && listScroll > 0 && window.scrollY === 0) {
				window.scrollTo(0, listScroll);
			}
			syncDay();
		}

		/**
		 * After a background morph the DOM carries the server's defaults again: the
		 * timeline's own first page, no zoom, the timeline view. Everything the reader
		 * owns goes back on top of it here — the morph itself only preserved what the
		 * server never writes.
		 */
		function afterMorph() {
			root.style.setProperty('--hour-width', hourWidth + 'px');
			if (listSpy) {
				listSpy.disconnect();
				listSpy = null;
				visibleDays.clear();
			}
			showView(currentView);
			if (currentPageKey) {
				showPageByKey('timeline', currentPageKey);
			}
			setCurrentDay(null);
			syncZoomButtons();
			applyTickStep();
		}

		root.addEventListener('click', function (event) {
			const el = event.target.closest('[data-pg-view],[data-pg-step],[data-pg-menu],[data-pg-page],[data-pg-close],[data-pg-open],[data-pg-zoom]');
			if (!el) {
				return;
			}
			const data = el.dataset;

			if (data.pgView !== undefined) {
				showView(data.pgView);
			} else if (data.pgStep !== undefined) {
				// the same two controls, two different instruments: the timeline steps
				// a page, the list scrolls to a day heading
				if (data.pgKind === 'list') {
					stepDay(Number(data.pgStep));
				} else {
					showPage(data.pgKind, activeIndex(data.pgKind) + Number(data.pgStep));
				}
			} else if (data.pgMenu !== undefined) {
				const menu = root.querySelector('[data-pg-menu-panel="' + data.pgMenu + '"]');
				if (menu && menu.classList.contains('is-open')) {
					closeMenus();
				} else {
					openMenu(data.pgMenu, el);
				}
			} else if (data.pgPage !== undefined) {
				closeMenus();
				if (data.pgKind === 'list') {
					scrollToDay(data.pgPage);
				} else {
					showPageByKey(data.pgKind, data.pgPage);
				}
			} else if (data.pgClose !== undefined) {
				closeMenus();
				closeSheet();
			} else if (data.pgOpen !== undefined) {
				openSheet(data.pgOpen, false, el);
			} else if (data.pgZoom !== undefined) {
				// a button press has no finger to anchor on, so hold the middle
				setZoom(hourWidth * (data.pgZoom === 'in' ? 1.25 : 1 / 1.25));
			}
		});

		// Still on the document rather than on the root: Escape has to work when focus
		// has ended up on <body>, which is outside the screen. The root is never removed
		// once inserted, so this listener has nothing to unbind — but it does have to
		// stand down while another screen is the one on show.
		document.addEventListener('keydown', function (event) {
			if (!document.contains(root) || root.closest('[data-screen][hidden]')) {
				return;
			}

			if (event.key === 'Escape') {
				closeMenus();
				closeSheet();
				return;
			}

			if (event.key === 'Tab' && trapCard) {
				cycleTab(event);
				return;
			}

			const target = event.target.closest ? event.target : null;
			if (!target) {
				return;
			}

			// arrow keys walk the tab strip, as the tablist pattern expects
			const tab = target.closest('[data-pg-view]');
			if (tab && (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'Home' || event.key === 'End')) {
				const list = tabs();
				const here = list.indexOf(tab);
				let next = here;
				if (event.key === 'ArrowLeft') {
					next = (here - 1 + list.length) % list.length;
				} else if (event.key === 'ArrowRight') {
					next = (here + 1) % list.length;
				} else if (event.key === 'Home') {
					next = 0;
				} else {
					next = list.length - 1;
				}
				event.preventDefault();
				showView(list[next].dataset.pgView);
				list[next].focus();
				return;
			}
			// A list row needs no key handling of its own any more. The control in it is
			// the inner <button class="pl-open">, which answers Enter and Space itself;
			// the branch that used to be here fired on the <article> and handed
			// closeSheet() that as the opener, so focus came back one level out of where
			// the reader had left it. The article keeps tabindex="-1" all the same — it
			// is where focus returns to after a pointer press on the card.
		});

		// the old deep link into a single programme keeps working, and now opens its detail
		function fromHash() {
			const matches = location.hash.match(/^#section-(\d+)-program-(\d+)$/);
			if (matches) {
				openSheet(matches[2], true, null);
			}
		}

		window.addEventListener('hashchange', function () {
			if (document.contains(root) && !root.closest('[data-screen][hidden]')) {
				fromHash();
			}
		});
		// a webfont landing changes what a label measures, and with it the scale
		// at which the ruler has to start thinning
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(function () {
				tickReach = 0;
				applyTickStep();
			});
		}

		// A background morph rewrites the screen to the server's defaults; this is
		// where the reader's own state goes back on top of it.
		document.addEventListener('screen:morphed', function (event) {
			if (event.target.contains && event.target.contains(root)) {
				afterMorph();
			}
		});

		// the scale outlives paging and view switching, but not the session
		restoreZoom();
		showPage('timeline', Math.max(activeIndex('timeline'), 0));
		// the strip opens naming the day at the top of the scroll; from here on the
		// observer names whichever day the reader has scrolled to
		setCurrentDay(listDays()[0] || null);
		showView(currentView);
		fromHash();
	}

	/**
	 * Wire every Program screen inside `scope` (default: the whole document).
	 * Safe to call repeatedly on the same screen.
	 */
	window.pgInit = function (scope) {
		(scope || document).querySelectorAll('[data-pg-root]').forEach(init);
	};

	// A server-rendered Program screen wires itself, so the screen keeps working even
	// if the loader never runs. Anything the loader inserts, the loader announces.
	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		window.pgInit(document);
	});

	document.addEventListener('screen:shown', function (event) {
		window.pgInit(event.target);
	});
})();
