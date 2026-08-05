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
 * The whole screen is one page: every (day, section) page, both views and every
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

			const title = root.querySelector('[data-pg-title="' + kind + '"]');
			if (title) {
				title.textContent = list[index].dataset.label;
			}
			root.querySelectorAll('[data-pg-page][data-pg-kind="' + kind + '"]').forEach(function (item) {
				item.classList.toggle('is-active', item.dataset.pgPage === list[index].dataset.key);
			});
			root.querySelectorAll('[data-pg-step][data-pg-kind="' + kind + '"]').forEach(function (arrow) {
				const target = index + Number(arrow.dataset.pgStep);
				arrow.disabled = target < 0 || target > list.length - 1;
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
			root.dataset.view = view;
			tabs().forEach(function (tab) {
				const active = tab.dataset.pgView === view;
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
				// roving tabindex: only the selected tab is in the tab order
				tab.tabIndex = active ? 0 : -1;
			});
			closeMenus();
		}

		function closeMenus() {
			let wasOpen = false;
			root.querySelectorAll('[data-pg-menu-panel]').forEach(function (menu) {
				wasOpen = wasOpen || menu.classList.contains('is-open');
				menu.classList.remove('is-open');
				menu.setAttribute('aria-hidden', 'true');
			});
			root.querySelectorAll('[data-pg-menu]').forEach(function (button) {
				button.setAttribute('aria-expanded', 'false');
			});
			// the day panel returns focus to its trigger, exactly as the sheet does
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
			}
			closeMenus();
			opener = trigger || null;
			sheet.classList.add('is-open');
			sheet.setAttribute('aria-hidden', 'false');
			sheetScroll.scrollTop = 0;
			sheetCard.focus();
		}

		function closeSheet() {
			if (!sheet.classList.contains('is-open')) {
				return;
			}
			sheet.classList.remove('is-open');
			sheet.setAttribute('aria-hidden', 'true');
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
			root.dataset.hourStep = hourWidth >= tickLabelReach() ? '1' : '2';
		}

		function syncZoomButtons() {
			root.querySelectorAll('[data-pg-zoom]').forEach(function (button) {
				button.disabled = button.dataset.pgZoom === 'in'
					? hourWidth >= zoomMax - 0.01
					: hourWidth <= zoomMin + 0.01;
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
			// from the live midpoint each move is what keeps the anchor honest
			setZoom(pinch.hourWidth * (span.dist / pinch.dist), span.midX);
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

		root.addEventListener('click', function (event) {
			const el = event.target.closest('[data-pg-view],[data-pg-step],[data-pg-menu],[data-pg-page],[data-pg-close],[data-pg-open],[data-pg-zoom]');
			if (!el) {
				return;
			}
			const data = el.dataset;

			if (data.pgView !== undefined) {
				showView(data.pgView);
			} else if (data.pgStep !== undefined) {
				showPage(data.pgKind, activeIndex(data.pgKind) + Number(data.pgStep));
			} else if (data.pgMenu !== undefined) {
				const menu = root.querySelector('[data-pg-menu-panel="' + data.pgMenu + '"]');
				if (menu && menu.classList.contains('is-open')) {
					closeMenus();
				} else {
					openMenu(data.pgMenu, el);
				}
			} else if (data.pgPage !== undefined) {
				showPageByKey(data.pgKind, data.pgPage);
				closeMenus();
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

			// the list rows are not buttons (they carry a heading), so they need this by hand
			const row = target.closest('.pl-item[data-pg-open]');
			if (row && (event.key === 'Enter' || event.key === ' ')) {
				event.preventDefault();
				openSheet(row.dataset.pgOpen, false, row);
			}
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

		// the scale outlives paging and view switching, but not the session
		restoreZoom();
		showPage('timeline', Math.max(activeIndex('timeline'), 0));
		showPage('list', Math.max(activeIndex('list'), 0));
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
