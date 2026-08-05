/**
 * The screen loader.
 *
 * Switching tabs does not load a document any more: <main> is a stack of screens, a
 * screen is fetched once (with `X-Screen: 1`, which makes the server render it without
 * the shell) and from then on switching is show/hide. Nothing is ever removed, so
 * state is *live* rather than restored — the map's iframe keeps running, the timeline
 * keeps its zoom and its horizontal scroll, a half-typed TIE code stays typed. A stale
 * screen whose HTML has changed is *morphed* into the new one rather than replaced, so
 * a background revalidation keeps all of that too.
 *
 * This is an enhancement layer, not a client router. Every link is a real <a href>;
 * with this file absent, or fetch/pushState missing, every tap is the plain navigation
 * it always was. Anything not on the tab bar (the handbook PDF, external links,
 * /admin/notify) and every non-GET (SkautIS login, the TIE form, logout, push
 * subscribe) is deliberately left to the browser: logging in changes both the app bar
 * and the registered marking on every programme, so a full load is the correctness
 * rule there, not laziness.
 */
(function () {
	'use strict';

	var main = document.querySelector('main');
	var first = main && main.querySelector('[data-screen]');
	if (!main || !first || !window.fetch || !window.history.pushState || !window.Map) {
		return;
	}

	var root = document.documentElement;
	/** The app has six screens; the cap is a backstop, not a feature. */
	var CACHE_LIMIT = 8;
	/** Older than this and a screen is revalidated in the background when next shown. */
	var STALE_AFTER = 5 * 60 * 1000;

	/** path -> {section, html, fetchedAt, shownAt, scrollTop, pending, checking} */
	var screens = new Map();
	var currentPath = first.dataset.screen || location.pathname;

	function pathOf(href) {
		return new URL(href, location.href).pathname;
	}

	/**
	 * The participating screens, in tab-bar order, with /profil after the last tab —
	 * which is what makes a move to the profile count as forward. The order is read
	 * off the rendered shell rather than hardcoded, so an event that enables a
	 * different set of features gets its own order for free.
	 */
	var order = [];
	document.querySelectorAll('.tabbar .tab').forEach(function (tab) {
		order.push(pathOf(tab.href));
	});
	var profileLink = document.querySelector('.appbar-profile');
	if (profileLink) {
		order.push(pathOf(profileLink.href));
	}
	order = order.filter(function (path, i) {
		return order.indexOf(path) === i;
	});

	screens.set(currentPath, {
		section: first,
		html: first.innerHTML,
		fetchedAt: Date.now(),
		shownAt: Date.now(),
		scrollTop: 0,
		pending: null,
		checking: false,
	});
	history.replaceState({screen: currentPath}, '');

	// --- fetching ---------------------------------------------------------

	/** Parse a fragment response. A <template> never runs a script it is given. */
	function parse(html) {
		var holder = document.createElement('template');
		holder.innerHTML = html;

		return holder.content.querySelector('[data-screen]');
	}

	function load(path) {
		return fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin'})
			.then(function (response) {
				// A non-200 is never a screen: a 404 and a 500 both answer with the
				// whole error page, shell and all, and injecting that into a <section>
				// would leave an app bar inside the app. Anything but a 200 falls
				// through to the plain navigation below, which shows the reader the
				// error page the server actually meant to send.
				if (!response.ok) {
					throw new Error(String(response.status));
				}

				return response.text();
			})
			.then(function (html) {
				var section = parse(html);
				if (!section) {
					throw new Error('no screen in response');
				}
				// insert hidden; showing it, and only then wiring it, is show()'s job —
				// measuring inside a hidden subtree returns 0
				section.hidden = true;
				main.appendChild(section);
				var entry = {
					section: section,
					html: section.innerHTML,
					fetchedAt: Date.now(),
					shownAt: 0,
					scrollTop: 0,
					pending: null,
					checking: false,
				};
				screens.set(path, entry);

				return entry;
			})
			.catch(function () {
				// an error page, a redirect to somewhere else, no network: fall back to
				// the plain navigation, which is always still correct
				return null;
			});
	}

	// --- the morph --------------------------------------------------------

	/**
	 * A screen is never replaced, only patched into the shape of the fresh one.
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
	 */

	function keyOf(node) {
		if (node.nodeType !== 1) {
			return null;
		}

		return node.id || node.getAttribute('data-key') || null;
	}

	/** Two nodes can stand in for one another only if they are the same kind of thing. */
	function kindOf(node) {
		return node.nodeType === 1 ? 'e:' + node.tagName : 'n:' + node.nodeType;
	}

	/** Moving a node that owns an iframe reloads it just as surely as replacing it does. */
	function ownsIframe(node) {
		return node.nodeType === 1
			&& (node.tagName === 'IFRAME' || !!node.querySelector('iframe'));
	}

	/**
	 * Attributes the fresh node states are made to match. Two things are left alone.
	 *
	 * An attribute the fresh node does not carry at all: the server never writes it,
	 * so it is the client's — --hour-width lives in .pg's style attribute,
	 * data-hour-step is the ruler's density, data-pg-ready the wiring flag, `disabled`
	 * the pager's own bookkeeping.
	 *
	 * And an attribute the markup itself declares as the client's through
	 * `data-morph-keep`, for the ones the server does write because it has to render
	 * *some* default: which view .pg is showing, which .tl-page is the active one.
	 * Writing the server's value and putting it back a line later is not free — in
	 * between, the screen is briefly a different shape, and a layout landing in that
	 * window takes the reader's scroll position with it. The loader knows nothing
	 * about which attributes those are; the screen says so.
	 */
	function patchAttributes(from, to) {
		var owned = from.getAttribute('data-morph-keep');
		owned = owned === null ? null : ' ' + owned + ' ';
		var attrs = to.attributes;
		for (var i = 0; i < attrs.length; i++) {
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

	function morphChildren(from, to) {
		var oldNodes = [];
		for (var node = from.firstChild; node; node = node.nextSibling) {
			oldNodes.push(node);
		}
		var keyed = new Map();
		/** unkeyed children queue up per kind, which is "position among same-tag siblings" */
		var pools = new Map();
		oldNodes.forEach(function (child) {
			var key = keyOf(child);
			if (key !== null) {
				if (!keyed.has(key)) {
					keyed.set(key, child);
				}

				return;
			}
			var kind = kindOf(child);
			if (!pools.has(kind)) {
				pools.set(kind, []);
			}
			pools.get(kind).push(child);
		});

		// match first, move second: matching against a tree that is being reordered
		// under the loop is how a morph starts producing nonsense
		var matched = new Set();
		var plan = [];
		for (var wanted = to.firstChild; wanted; wanted = wanted.nextSibling) {
			var key = keyOf(wanted);
			var match = null;
			if (key !== null) {
				var candidate = keyed.get(key);
				if (candidate && !matched.has(candidate) && kindOf(candidate) === kindOf(wanted)) {
					match = candidate;
				}
			} else {
				var pool = pools.get(kindOf(wanted));
				match = pool && pool.length > 0 ? pool.shift() : null;
			}
			if (match) {
				matched.add(match);
			}
			plan.push({wanted: wanted, match: match});
		}

		var cursor = from.firstChild;
		plan.forEach(function (step) {
			if (!step.match) {
				from.insertBefore(document.importNode(step.wanted, true), cursor);

				return;
			}
			if (step.match === cursor) {
				cursor = cursor.nextSibling;
			} else if (!ownsIframe(step.match)) {
				from.insertBefore(step.match, cursor);
			}
			patch(step.match, step.wanted);
		});

		oldNodes.forEach(function (child) {
			if (!matched.has(child) && child.parentNode === from) {
				from.removeChild(child);
			}
		});
	}

	/** Focus and the caret survive the morph if the element they were on does. */
	function captureFocus(section) {
		var focused = document.activeElement;
		if (!focused || !section.contains(focused)) {
			return null;
		}
		var state = {element: focused, start: null, end: null};
		try {
			state.start = focused.selectionStart;
			state.end = focused.selectionEnd;
		} catch (e) {
			// a <button> has no selection to remember
		}

		return state;
	}

	function restoreFocus(state) {
		if (!state || !document.contains(state.element) || document.activeElement === state.element) {
			return;
		}
		state.element.focus({preventScroll: true});
		if (state.start !== null) {
			try {
				state.element.setSelectionRange(state.start, state.end);
			} catch (e) {
				// the field stopped accepting a range: the focus alone is the point
			}
		}
	}

	// --- freshness --------------------------------------------------------

	/** A screen the reader is in the middle of must not be pulled out from under them. */
	function busy(section) {
		if (section.querySelector('.sheet.is-open, .pager-menu.is-open')) {
			return true;
		}
		var focused = document.activeElement;

		return !!(focused && section.contains(focused)
			&& /^(INPUT|TEXTAREA|SELECT)$/.test(focused.tagName));
	}

	function applyPending(entry) {
		if (!entry.pending || busy(entry.section)) {
			return;
		}
		var fresh = entry.pending;
		entry.pending = null;
		var focus = captureFocus(entry.section);
		patchAttributes(entry.section, fresh.section);
		morphChildren(entry.section, fresh.section);
		restoreFocus(focus);
		entry.html = fresh.html;
		// The screen's own script puts back what it, and not the server, owns: which
		// view is on show, which page it is on, the hour scale. Dispatched
		// synchronously, in this same task: the fresh markup names the server's page
		// as the active one, and a layout performed between the two would hide the
		// page the reader is on — taking its scroll position with it.
		entry.section.dispatchEvent(new CustomEvent('screen:morphed', {bubbles: true}));
		if (!entry.section.hidden) {
			dressShell(entry.section);
		}
	}

	/**
	 * Stale-while-revalidate: the cached screen is shown at once, always, and only a
	 * screen whose HTML has actually changed is morphed into the new one — an
	 * unchanged one is not touched at all, so it can neither flicker nor lose state.
	 */
	function revalidate(path, entry) {
		if (entry.checking || Date.now() - entry.fetchedAt < STALE_AFTER) {
			return;
		}
		entry.checking = true;
		fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin'})
			.then(function (response) {
				return response.ok ? response.text() : null;
			})
			.then(function (html) {
				entry.checking = false;
				var fresh = html === null ? null : parse(html);
				if (!fresh) {
					return;
				}
				entry.fetchedAt = Date.now();
				var freshHtml = fresh.innerHTML;
				if (freshHtml === entry.html) {
					return;
				}
				// if the reader is busy in it, the morph waits for the next time it is shown
				entry.pending = {section: fresh, html: freshHtml};
				applyPending(entry);
			})
			.catch(function () {
				entry.checking = false;
			});
	}

	// --- showing ----------------------------------------------------------

	function announce(section) {
		section.dispatchEvent(new CustomEvent('screen:shown', {bubbles: true}));
	}

	function dressShell(section) {
		document.title = section.dataset.docTitle || document.title;
		var title = document.querySelector('.appbar-title');
		if (title) {
			title.textContent = section.dataset.title || '';
		}
		var tab = section.dataset.tab || '';
		document.body.className = 'screen-' + (tab !== '' ? tab : 'other');
		document.querySelectorAll('.tabbar .tab').forEach(function (link) {
			link.classList.toggle('is-active', pathOf(link.href) === currentPath);
		});
	}

	function evict() {
		if (screens.size <= CACHE_LIMIT) {
			return;
		}
		var stalest = null;
		var stalestPath = null;
		screens.forEach(function (entry, path) {
			if (path !== currentPath && (stalest === null || entry.shownAt < stalest.shownAt)) {
				stalest = entry;
				stalestPath = path;
			}
		});
		if (stalestPath !== null) {
			stalest.section.remove();
			screens.delete(stalestPath);
		}
	}

	/**
	 * Screens slide by tab order — right along the bar means in from the right. The
	 * View Transitions API does it; where it is missing, or the reader asked for
	 * reduced motion, the swap is instant. There is no JS animation fallback, and
	 * deliberately no swipe gesture: the timeline is itself a horizontally scrolling
	 * surface, where a horizontal drag means "scroll the hours".
	 */
	function transition(swap, back) {
		var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (!document.startViewTransition || reduced) {
			swap();

			return;
		}
		root.dataset.nav = back ? 'back' : 'forward';
		var done = function () {
			delete root.dataset.nav;
		};
		document.startViewTransition(swap).finished.then(done, done);
	}

	function show(path, entry, push) {
		if (path === currentPath) {
			return;
		}
		var from = order.indexOf(currentPath);
		var to = order.indexOf(path);
		// popstate gets the reverse direction for free: the order comparison is the
		// same one, and coming back reverses which side is bigger
		var back = from >= 0 && to >= 0 && to < from;

		if (push) {
			history.pushState({screen: path}, '', path);
		}

		transition(function () {
			var leaving = screens.get(currentPath);
			if (leaving) {
				leaving.scrollTop = window.scrollY;
				leaving.section.hidden = true;
			}
			entry.section.hidden = false;
			entry.shownAt = Date.now();
			currentPath = path;
			dressShell(entry.section);
			window.scrollTo(0, entry.scrollTop || 0);
			// A morph deferred from last time lands here rather than a few lines up,
			// for the same reason the wiring does: the screen's own script measures
			// after one, and every measurement inside a hidden subtree returns 0.
			applyPending(entry);
			// insert -> show -> init, in that order and inside the transition's own
			// update, so the snapshot the browser animates is the wired screen
			announce(entry.section);
			// a keyboard or screen-reader user lands in the new screen instead of
			// carrying on reading the old one
			entry.section.focus({preventScroll: true});
		}, back);

		evict();
		revalidate(path, entry);
	}

	function go(path, push) {
		var entry = screens.get(path);
		if (entry) {
			show(path, entry, push);

			return;
		}
		load(path).then(function (loaded) {
			if (!loaded) {
				location.href = path;

				return;
			}
			show(path, loaded, push);
		});
	}

	// --- links ------------------------------------------------------------

	document.addEventListener('click', function (event) {
		if (event.defaultPrevented || event.button !== 0
			|| event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;
		}
		var link = event.target.closest ? event.target.closest('a[href]') : null;
		if (!link || link.target === '_blank' || link.hasAttribute('download')
			|| link.origin !== location.origin) {
			return;
		}
		var url = new URL(link.href);
		// same screen (a hash link, or the tab you are already on), a query string, a
		// cross-screen deep link, or a destination that is not a screen: all of those
		// stay exactly what they were
		if (url.pathname === currentPath || url.search !== '' || url.hash !== ''
			|| order.indexOf(url.pathname) < 0) {
			return;
		}
		event.preventDefault();
		go(url.pathname, true);
	});

	window.addEventListener('popstate', function () {
		var path = location.pathname;
		if (order.indexOf(path) < 0) {
			location.reload();

			return;
		}
		go(path, false);
	});
})();
