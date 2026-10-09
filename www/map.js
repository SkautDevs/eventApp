/**
 * The Mapa screen's two views: Google's interactive map and the handbook's own plan.
 *
 * Like www/programs.js, one idempotent window.mapInit(scope), wired on DOMContentLoaded
 * for a server-rendered screen and on screen:shown for one the loader inserted. Nothing
 * is ever detached: hiding the Google pane keeps its iframe running, which is the point
 * of the screen stack. The plan zooms by its width inside a box that scrolls both ways —
 * the browser's own panning, no transform — and the anchor stays put, as on the timeline.
 */
(function () {
	'use strict';

	const ZOOM_MIN = 1;
	const ZOOM_MAX = 6;
	const ZOOM_STEP = 1.4;

	/** sessionStorage, read (one argument) or written; private browsing simply forgets. */
	function store(key, value) {
		try {
			if (value === undefined) {
				return sessionStorage.getItem(key);
			}
			sessionStorage.setItem(key, value);
		} catch (e) {
			// the choice simply does not outlive the page
		}
		return null;
	}

	function init(root) {
		if (!root || root.dataset.mapReady === '1') {
			return;
		}
		root.dataset.mapReady = '1';

		const slug = root.dataset.mapEvent || '';
		const viewKey = 'mapView:' + slug;
		const zoomKey = 'planZoom:' + slug;
		const hasTabs = root.querySelector('[role="tab"][data-map-view]') !== null;
		let view = root.dataset.view || 'google';
		let zoom = ZOOM_MIN;
		// the image's width is set once without the scroll maths: before that, the box
		// holds the plan at 100% whatever `zoom` says, so a ratio against it would be wrong
		let applied = false;

		function tabs() {
			return Array.from(root.querySelectorAll('[role="tab"][data-map-view]'));
		}

		function setView(next) {
			view = next;
			if (root.dataset.view !== next) {
				root.dataset.view = next;
			}
			tabs().forEach(function (tab) {
				const on = tab.dataset.mapView === next;
				if (tab.getAttribute('aria-selected') !== String(on)) {
					tab.setAttribute('aria-selected', String(on));
				}
				tab.tabIndex = on ? 0 : -1;
			});
			if (next === 'plan') {
				applyZoom(zoom);
			}
		}

		function choose(next) {
			setView(next);
			store(viewKey, next);
		}

		/** Sets the plan's width to `next` × the box, keeping the point under (ax, ay) where it is. */
		function applyZoom(next, ax, ay) {
			const plan = root.querySelector('[data-plan]');
			const img = root.querySelector('[data-plan-img]');
			if (!plan || !img) {
				return;
			}
			next = Math.min(Math.max(next, ZOOM_MIN), ZOOM_MAX);
			if (applied) {
				const rect = plan.getBoundingClientRect();
				const x = ax === undefined ? rect.width / 2 : ax - rect.left;
				const y = ay === undefined ? rect.height / 2 : ay - rect.top;
				const ratio = next / zoom;
				const left = (plan.scrollLeft + x) * ratio - x;
				const top = (plan.scrollTop + y) * ratio - y;
				img.style.width = (next * 100) + '%';
				plan.scrollLeft = Math.max(left, 0);
				plan.scrollTop = Math.max(top, 0);
			} else {
				img.style.width = (next * 100) + '%';
				applied = true;
			}
			zoom = next;
			root.querySelectorAll('[data-plan-zoom]').forEach(function (button) {
				const disabled = button.dataset.planZoom === 'in' ? zoom >= ZOOM_MAX - 0.01 : zoom <= ZOOM_MIN + 0.01;
				if (button.disabled !== disabled) {
					button.disabled = disabled;
				}
			});
			store(zoomKey, String(zoom));
		}

		root.addEventListener('click', function (event) {
			const el = event.target.closest('[data-map-view],[data-plan-zoom]');
			if (!el) {
				return;
			}
			if (el.dataset.mapView !== undefined) {
				choose(el.dataset.mapView);
				// the offline note's button moves the reader, so the focus goes with them
				if (!el.matches('[role="tab"]')) {
					const tab = root.querySelector('[role="tab"][data-map-view="' + el.dataset.mapView + '"]');
					if (tab) {
						tab.focus();
					}
				}
			} else {
				applyZoom(zoom * (el.dataset.planZoom === 'in' ? ZOOM_STEP : 1 / ZOOM_STEP));
			}
		});

		// arrow keys walk the two tabs, as the tablist pattern expects
		root.addEventListener('keydown', function (event) {
			const tab = event.target.closest && event.target.closest('[role="tab"][data-map-view]');
			if (!tab || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) < 0) {
				return;
			}
			const list = tabs();
			const here = list.indexOf(tab);
			const next = event.key === 'Home' ? 0 : event.key === 'End' ? list.length - 1
				: (here + (event.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length;
			event.preventDefault();
			choose(list[next].dataset.mapView);
			list[next].focus();
		});

		// two fingers on the plan zoom it, anchored between them
		const pointers = new Map();
		let pinch = null;

		function fingers() {
			const list = Array.from(pointers.values());
			const a = list[0];
			const b = list[1];
			return {dist: Math.hypot(a.x - b.x, a.y - b.y), midX: (a.x + b.x) / 2, midY: (a.y + b.y) / 2};
		}

		root.addEventListener('pointerdown', function (event) {
			if (event.pointerType !== 'touch' || !event.target.closest('[data-plan]')) {
				return;
			}
			pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
			if (pointers.size === 2) {
				pinch = {dist: fingers().dist, zoom: zoom};
			}
		});
		root.addEventListener('pointermove', function (event) {
			if (!pointers.has(event.pointerId)) {
				return;
			}
			pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
			if (!pinch || pointers.size < 2) {
				return;
			}
			const now = fingers();
			if (now.dist > 0 && pinch.dist > 0) {
				applyZoom(pinch.zoom * now.dist / pinch.dist, now.midX, now.midY);
			}
		});
		function release(event) {
			pointers.delete(event.pointerId);
			if (pointers.size < 2) {
				pinch = null;
			}
		}
		root.addEventListener('pointerup', release);
		root.addEventListener('pointercancel', release);

		// data-morph-keep keeps data-view, but the tabs' aria-selected and tabindex are the
		// server's again after a morph, so the reader's view is re-asserted on them
		document.addEventListener('screen:morphed', function (event) {
			if (event.target.contains && event.target.contains(root)) {
				setView(view);
			}
		});

		const storedZoom = parseFloat(store(zoomKey));
		if (isFinite(storedZoom)) {
			zoom = Math.min(Math.max(storedZoom, ZOOM_MIN), ZOOM_MAX);
		}
		if (hasTabs) {
			// offline the Google map is a blank box, so the plan is what opens
			const stored = store(viewKey);
			setView(navigator.onLine === false ? 'plan' : (stored === 'plan' || stored === 'google' ? stored : 'google'));
		} else {
			setView(view);
		}
	}

	window.mapInit = function (scope) {
		(scope || document).querySelectorAll('[data-map-root]').forEach(init);
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			window.mapInit(document);
		});
	} else {
		window.mapInit(document);
	}

	document.addEventListener('screen:shown', function (event) {
		window.mapInit(event.target);
	});
})();
