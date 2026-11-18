/**
 * The screen loader.
 *
 * Switching tabs does not load a document any more: <main> is a stack of screens, a
 * screen is fetched once (with `X-Screen: 1`, which makes the server render it without
 * the shell) and from then on switching is show/hide. Nothing is ever removed, so
 * state is *live* rather than restored — the map's iframe keeps running, the timeline
 * keeps its zoom and its horizontal scroll, a scrolled list stays scrolled. A stale
 * screen whose HTML has changed is *morphed* into the new one rather than replaced, so
 * a background revalidation keeps all of that too.
 *
 * This is an enhancement layer, not a client router. Every link is a real <a href>;
 * with this file absent, or fetch/pushState missing, every tap is the plain navigation
 * it always was. Anything not on the tab bar (the handbook PDF, external links,
 * /admin/notify) and every non-GET (the pairing start, the logout, push
 * subscribe) is deliberately left to the browser: logging in changes both the app bar
 * and the registered marking on every programme, so a full load is the correctness
 * rule there, not laziness. The one exception is the end of a pairing, which is a GET
 * poll answered by JSON: profile/pairing.js then reloads the document itself.
 *
 * startLoader() files the first screen as it was served, so it must run before any
 * other module draws into that screen: a later revalidation compares against this copy.
 */

import {emitScreenFreshness, emitScreenHidden, emitScreenLoaded, emitScreenLoading, emitScreenMorphed, emitScreenShown, onScreenShown} from '../events.js';
import {captureFocus, morphChildren, patchAttributes, restoreFocus} from './morph.js';

const root = document.documentElement;
/** The app has six screens; the cap is a backstop, not a feature. */
const CACHE_LIMIT = 8;
/** Older than this and a screen is revalidated in the background when next shown. */
const STALE_AFTER = 5 * 60 * 1000;
/** How long a screen the reader is waiting for may take before the plain navigation takes over. */
const LOAD_TIMEOUT = 8000;

/** <main>, once startLoader() found it. */
let main = null;
/** path -> {section, html, fetchedAt, shownAt, scrollTop, pending, checking} */
const screens = new Map();
let currentPath = null;
/** Paths the worker reported as changed before the loader had them (see the messages below). */
const staleOnArrival = new Set();
/**
 * One fetch per screen at a time: a tap on a screen the warm-up is already fetching
 * waits for that fetch instead of inserting the screen twice.
 */
const inflight = new Map();

/**
 * The participating screens, in tab-bar order, with /profil after the last tab —
 * which is what makes a move to the profile count as forward. The order is read
 * off the rendered shell rather than hardcoded, so an event that enables a
 * different set of features gets its own order for free.
 */
let order = [];

/**
 * Which navigation is the current one. A fetch takes as long as it takes, and the
 * reader is free to tap on or press Back while it is in flight; whatever resolved
 * last would otherwise win, minutes later if the network is bad enough. Every
 * navigation — a tap, a popstate — takes the next token, and a fetch that comes
 * back holding an old one has been overtaken.
 */
let navigation = 0;
/**
 * The destination the reader last asked for, which is not the same thing as the
 * screen on show while a fetch is in flight. The entry is recorded when they tap
 * rather than when the screen arrives: a tap is a navigation whether or not its
 * fetch wins the race to be shown, so five taps leave five entries to walk back
 * through, and pressing Back while one is loading returns to the screen before it.
 * A second tap on the destination already asked for is not a second navigation.
 */
let wantedPath = null;

function pathOf(href) {
	return new URL(href, location.href).pathname;
}

// --- fetching ---------------------------------------------------------

/** Parse a fragment response. A <template> never runs a script it is given. */
function parse(html) {
	const holder = document.createElement('template');
	holder.innerHTML = html;

	return holder.content.querySelector('[data-screen]');
}

function load(path) {
	if (inflight.has(path)) {
		return inflight.get(path);
	}
	const pending = fetchScreen(path).then(entry => {
		inflight.delete(path);

		return entry;
	});
	inflight.set(path, pending);

	return pending;
}

function fetchScreen(path) {
	// lie-fi: a fetch that never ends would leave the tap hanging for minutes
	const controller = window.AbortController ? new AbortController() : null;
	const timer = controller
		? setTimeout(() => {
				controller.abort();
			}, LOAD_TIMEOUT)
		: null;

	return fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin', signal: controller ? controller.signal : undefined})
		.then(response => {
			// A non-200 is never a screen: a 404 and a 500 both answer with the
			// whole error page, shell and all, and injecting that into a <section>
			// would leave an app bar inside the app. Anything but a 200 falls
			// through to the plain navigation below, which shows the reader the
			// error page the server actually meant to send.
			if (!response.ok) {
				throw new Error(String(response.status));
			}
			// A redirect answered something other than what was asked for — a
			// login wall, a canonical URL, another screen entirely — and its
			// markup would be cached under this path and shown for it from then
			// on. The browser follows it where the reader can see it.
			if (response.redirected) {
				throw new Error('redirected');
			}

			return response.text();
		})
		.then(html => {
			clearTimeout(timer);
			const section = parse(html);
			if (!section) {
				throw new Error('no screen in response');
			}
			// The screen states its own path; one that is not the path asked for
			// is somebody else's screen and must not be filed under this one.
			if (section.dataset.screen !== path) {
				throw new Error('screen is ' + section.dataset.screen);
			}
			// insert hidden; showing it, and only then wiring it, is show()'s job —
			// measuring inside a hidden subtree returns 0
			section.hidden = true;
			main.appendChild(section);
			const entry = {
				section: section,
				html: section.innerHTML,
				// the worker may already have said this copy is old
				fetchedAt: staleOnArrival.delete(path) ? 0 : Date.now(),
				shownAt: 0,
				scrollTop: 0,
				pending: null,
				checking: false,
			};
			screens.set(path, entry);

			return entry;
		})
		.catch(() => {
			clearTimeout(timer);
			// an error page, a redirect to somewhere else, no network, a deadline: fall back to
			// the plain navigation, which is always still correct
			return null;
		});
}

/** The screens of the tab order not loaded yet, in that order: what the warm-up (warmup.js) fetches. */
export function missingScreens() {
	return order.filter(path => !screens.has(path));
}

/** Fetch a screen and insert it hidden; a promise of whether it arrived. */
export function preload(path) {
	return load(path).then(entry => entry !== null);
}

// --- freshness --------------------------------------------------------

/**
 * The data timestamp is the section's own, and the section's attributes are not part
 * of the innerHTML a revalidation compares, nor does patchAttributes() remove one the
 * fresh markup no longer has. So both are carried over here: an unchanged screen still
 * learns when its data was last confirmed, and a morphed one drops a data-stale the
 * server stopped sending.
 */
export function syncFreshness(section, fresh) {
	let changed = false;
	['data-fetched-at', 'data-stale'].forEach(name => {
		const value = fresh.getAttribute(name);
		if (value === null) {
			if (section.hasAttribute(name)) {
				section.removeAttribute(name);
				changed = true;
			}
		} else if (section.getAttribute(name) !== value) {
			section.setAttribute(name, value);
			changed = true;
		}
	});
	// the unchanged path announces nothing else, and the network banner (shell/netstate.js)
	// still has to learn the new time
	if (changed) {
		emitScreenFreshness(section);
	}
}

/** A screen the reader is in the middle of — the sheet open, a field focused — must not be pulled out from under them. */
function busy(section) {
	if (section.querySelector('.sheet.is-open')) {
		return true;
	}
	const focused = document.activeElement;

	return !!(focused && section.contains(focused) && /^(INPUT|TEXTAREA|SELECT)$/.test(focused.tagName));
}

function applyPending(entry) {
	if (!entry.pending || busy(entry.section)) {
		return;
	}
	const fresh = entry.pending;
	entry.pending = null;
	const focus = captureFocus(entry.section);
	patchAttributes(entry.section, fresh.section);
	syncFreshness(entry.section, fresh.section);
	morphChildren(entry.section, fresh.section);
	restoreFocus(focus);
	entry.html = fresh.html;
	// The screen's own script puts back what it, and not the server, owns: which
	// view is on show, which page it is on, the hour scale. Dispatched
	// synchronously, in this same task: the fresh markup names the server's page
	// as the active one, and a layout performed between the two would hide the
	// page the reader is on — taking its scroll position with it.
	emitScreenMorphed(entry.section);
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
	// no-cache: the worker answers this from the network (www/sw.js); its cached copy
	// is what the reader is already looking at
	fetch(path, {headers: {'X-Screen': '1'}, credentials: 'same-origin', cache: 'no-cache'})
		.then(response => {
			// the same two rules as load(): a non-200 and a redirect are both
			// something other than this screen. A revalidation that finds one
			// simply leaves the cached screen alone — it runs in the background,
			// behind a reader who asked for nothing, so it may not navigate.
			// An answer the worker took from its own cache (no network) is not a
			// confirmation of anything: taking it as one would stamp the screen fresh
			// and skip the real check for five minutes after the signal comes back.
			return response.ok && !response.redirected && response.headers.get('X-From-Cache') !== '1' ? response.text() : null;
		})
		.then(html => {
			entry.checking = false;
			const fresh = html === null ? null : parse(html);
			if (!fresh || fresh.dataset.screen !== path) {
				return;
			}
			entry.fetchedAt = Date.now();
			const freshHtml = fresh.innerHTML;
			if (freshHtml === entry.html) {
				// a held older morph must not re-apply over this newer, identical fetch
				entry.pending = null;
				syncFreshness(entry.section, fresh);
				return;
			}
			// if the reader is busy in it, the morph waits for the next time it is shown
			entry.pending = {section: fresh, html: freshHtml};
			applyPending(entry);
		})
		.catch(() => {
			entry.checking = false;
		});
}

// --- showing ----------------------------------------------------------

function dressShell(section) {
	document.title = section.dataset.docTitle || document.title;
	// A swap changes the document title and the app bar, and no screen reader
	// announces either on its own — a soft navigation is silent where a real one
	// speaks. The shell's one live region gets the screen's name here. Only here,
	// i.e. only on a swap: the first screen is server-rendered and this never runs
	// for it, so a cold load announces nothing on top of the page it just read out.
	const live = document.querySelector('[data-screen-live]');
	if (live) {
		live.textContent = section.dataset.title || '';
	}
	const title = document.querySelector('.appbar-title');
	if (title) {
		title.textContent = section.dataset.title || '';
	}
	const tab = section.dataset.tab || '';
	document.body.className = 'screen-' + (tab !== '' ? tab : 'other');
	// the app bar's profile link is lit like a tab while Profil is shown
	document.querySelectorAll('.tabbar .tab, .appbar-profile').forEach(link => {
		const active = pathOf(link.href) === currentPath;
		link.classList.toggle('is-active', active);
		// The fill is pixels and .is-active is a class name; aria-current is the
		// only thing that names the current tab to a screen reader. The server
		// writes it on the screen it renders, so a swap that toggled the class
		// alone would leave it on the tab the reader has just left — being told
		// the wrong tab is worse than being told none.
		if (active) {
			link.setAttribute('aria-current', 'page');
		} else {
			link.removeAttribute('aria-current');
		}
	});
}

function evict() {
	if (screens.size <= CACHE_LIMIT) {
		return;
	}
	let stalest = null;
	let stalestPath = null;
	screens.forEach((entry, path) => {
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
	const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	if (!document.startViewTransition || reduced) {
		swap();

		return;
	}
	root.dataset.nav = back ? 'back' : 'forward';
	const done = () => {
		delete root.dataset.nav;
	};
	document.startViewTransition(swap).finished.then(done, done);
}

function show(path, entry) {
	if (path === currentPath) {
		return;
	}
	const from = order.indexOf(currentPath);
	const to = order.indexOf(path);
	// popstate gets the reverse direction for free: the order comparison is the
	// same one, and coming back reverses which side is bigger
	const back = from >= 0 && to >= 0 && to < from;

	transition(() => {
		const leaving = screens.get(currentPath);
		if (leaving) {
			leaving.scrollTop = window.scrollY;
			leaving.section.hidden = true;
			// a screen's own dialogs must not outlive it (programs/sheet.js)
			emitScreenHidden(leaving.section);
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
		// a keyboard or screen-reader user lands in the new screen instead of
		// carrying on reading the old one; before the wiring, so a dialog the
		// wiring opens (a deep link) keeps the focus it takes
		entry.section.focus({preventScroll: true});
		// insert -> show -> init, in that order and inside the transition's own
		// update, so the snapshot the browser animates is the wired screen
		emitScreenShown(entry.section);
	}, back);

	evict();
	revalidate(path, entry);
}

function go(path, push) {
	const token = ++navigation;
	if (push && path !== wantedPath) {
		history.pushState({screen: path}, '', path);
	}
	wantedPath = path;
	const entry = screens.get(path);
	if (entry) {
		// an earlier tap may still be loading; this one is not, so the bar goes
		emitScreenLoaded();
		show(path, entry);

		return;
	}
	// the loading bar (shell/progress.js): only for a fetch the reader is waiting for,
	// never for a background revalidation
	emitScreenLoading();
	load(path).then(loaded => {
		// An overtaken screen that did arrive is kept: it is a warm cache entry
		// either way, and the next visit to it is then instant. It is only not
		// shown, and not pushed, because the reader is somewhere else now.
		if (token !== navigation) {
			return;
		}
		emitScreenLoaded();
		if (!loaded) {
			// The fallback throws the whole app away — the cache, the map's live
			// iframe, the timeline's zoom — so it may only fire for the
			// navigation the reader is actually waiting for.
			location.href = path;

			return;
		}
		show(path, loaded);
	});
}

// --- the worker's messages ---------------------------------------------

/**
 * The service worker (www/sw.js) knows two things the loader does not: that the copy
 * of a screen it served differs from the server's (screen-updated), and that a push
 * has just arrived (news-updated). Either way the cached screen is marked stale and,
 * if it is the one on show, revalidated now; the morph does the rest. A screen not
 * shown yet is revalidated by show() the next time it is.
 */
function refreshNow(entry, path) {
	entry.fetchedAt = 0;
	if (path === currentPath) {
		revalidate(path, entry);
	}
}

function listenToTheWorker() {
	if (!('serviceWorker' in navigator)) {
		return;
	}
	navigator.serviceWorker.addEventListener('message', event => {
		const data = event.data || {};
		if (data.type === 'screen-updated' && typeof data.path === 'string') {
			const entry = screens.get(data.path);
			if (entry) {
				refreshNow(entry, data.path);
			} else {
				// still in flight: load() files it as stale when it lands
				staleOnArrival.add(data.path);
			}
		} else if (data.type === 'news-updated') {
			screens.forEach((candidate, path) => {
				const tab = candidate.section.dataset.tab;
				if (tab === 'info' || tab === 'homepage' || (tab === 'programs' && typeof data.programme === 'number')) {
					refreshNow(candidate, path);
				}
			});
		}
	});
	// messages wait in a queue until this is called (or onmessage is set)
	navigator.serviceWorker.startMessages();
}

// --- links ------------------------------------------------------------

function onClick(event) {
	if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
		return;
	}
	const link = event.target.closest ? event.target.closest('a[href]') : null;
	if (!link || link.target === '_blank' || link.hasAttribute('download') || link.origin !== location.origin) {
		return;
	}
	// The skip link names the path the document was first served for; after a swap that
	// is another screen, and following it would throw the live app away. Its job is to
	// move to the content: the visible screen takes the focus, nothing navigates.
	if (link.classList.contains('skip-link')) {
		event.preventDefault();
		const shown = screens.get(currentPath);
		if (shown) {
			shown.section.focus();
		}

		return;
	}
	const url = new URL(link.href);
	// The tab you are already on: a native app does not reload the screen, it scrolls
	// it back to the top. A plain navigation here would throw the live app away.
	if (url.pathname === currentPath && url.search === '' && url.hash === '') {
		event.preventDefault();
		const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		window.scrollTo({top: 0, behavior: still ? 'auto' : 'smooth'});

		return;
	}
	// A deep link the page marks for it (the homepage's next-programme card) stays in
	// the app: the entry is pushed with its hash, and the screen reads the hash itself
	// — on its first wiring when it is new, on hashchange when it is already here.
	if (link.hasAttribute('data-deep-link') && url.search === '' && url.hash !== '' && url.pathname !== currentPath && order.indexOf(url.pathname) >= 0) {
		event.preventDefault();
		const known = screens.get(url.pathname);
		history.pushState({screen: url.pathname}, '', url.pathname + url.hash);
		wantedPath = url.pathname;
		// A screen ignores a hash while it is hidden, and with motion the swap waits
		// for the view transition, so the hash goes out once the screen is shown.
		if (known) {
			const stop = onScreenShown(target => {
				if (target === known.section) {
					stop();
					window.dispatchEvent(new HashChangeEvent('hashchange'));
				}
			});
		}
		go(url.pathname, false);

		return;
	}
	// same screen with a hash, a query string, a cross-screen deep link, or a
	// destination that is not a screen: all of those stay exactly what they were
	if (url.pathname === currentPath || url.search !== '' || url.hash !== '' || order.indexOf(url.pathname) < 0) {
		return;
	}
	event.preventDefault();
	go(url.pathname, true);
}

// Back is a navigation like any other, so it takes the next token too — which is
// what stops a tap still in flight from undoing it once its fetch lands.
function onPopstate() {
	const path = location.pathname;
	if (order.indexOf(path) < 0) {
		location.reload();

		return;
	}
	go(path, false);
}

/**
 * Files the first screen as it was served, writes its history entry and wires the
 * worker's messages, the links and Back. Returns false, having done nothing, on a page
 * it cannot drive: no <main> or no screen in it, or no fetch, pushState or Map; every
 * tap there stays the plain navigation it always was.
 */
export function startLoader() {
	const found = document.querySelector('main');
	const first = found && found.querySelector('[data-screen]');
	if (!found || !first || !window.fetch || !window.history.pushState || !window.Map) {
		return false;
	}
	main = found;
	currentPath = first.dataset.screen || location.pathname;

	document.querySelectorAll('.tabbar .tab').forEach(tab => {
		order.push(pathOf(tab.href));
	});
	const profileLink = document.querySelector('.appbar-profile');
	if (profileLink) {
		order.push(pathOf(profileLink.href));
	}
	order = order.filter((path, i) => order.indexOf(path) === i);

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
	wantedPath = currentPath;

	listenToTheWorker();
	document.addEventListener('click', onClick);
	window.addEventListener('popstate', onPopstate);

	return true;
}
