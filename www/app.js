/**
 * The screen loader.
 *
 * Switching tabs does not load a document any more: <main> is a stack of screens, a
 * screen is fetched once (with `X-Screen: 1`, which makes the server render it without
 * the shell) and from then on switching is show/hide. Nothing is ever removed, so
 * state is *live* rather than restored — the map's iframe keeps running, the timeline
 * keeps its zoom and its horizontal scroll, a half-typed TIE code stays typed.
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
		entry.section.innerHTML = entry.pending;
		entry.html = entry.pending;
		entry.pending = null;
		if (!entry.section.hidden) {
			announce(entry.section);
		}
	}

	/**
	 * Stale-while-revalidate: the cached screen is shown at once, always, and only a
	 * screen whose HTML has actually changed is replaced — an unchanged one never
	 * flickers and never loses its state.
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
				if (fresh.innerHTML === entry.html) {
					return;
				}
				// if the reader is busy in it, the swap waits for the next time it is shown
				entry.pending = fresh.innerHTML;
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
			applyPending(entry);
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
