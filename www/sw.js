/**
 * The event's service worker: its push notifications, and the offline copy of the app.
 *
 * One worker per event, at scope /<slug>/ — registered on every page by www/shell.js,
 * and again by www/push.js when notifications are turned on (register() is idempotent).
 * What it keeps comes from the server: /<slug>/precache.json lists the screens and the
 * content-hashed asset URLs (App\Http\Precache), so this file never changes when an
 * asset does. Each list version gets a cache of its own, eventapp-<slug>-<version>, and
 * a version replaces the one before it in one of two ways. A new sw.js goes the
 * browser's way: install fills the new cache, activate drops the others. A new list
 * version under an unchanged sw.js — the usual deploy — never reaches install, because
 * the browser reinstalls a worker only when its script changes; the version check that
 * runs after the first page from the network (checkVersion()) picks it up instead.
 *
 * Rollback: a sw.js with only the push and notificationclick handlers plus an activate
 * that deletes every PREFIX cache (docs/deployment.md).
 */

const SCOPE = self.registration.scope;
const BASE = new URL(SCOPE).pathname;
const SLUG = BASE.split('/')[1];
const PREFIX = 'eventapp-' + SLUG + '-';
/**
 * This event's caches: PREFIX and an 8-hex version, nothing longer. PREFIX alone is a
 * prefix of another event's caches too (ab-26 and ab-26-27), whose caches this worker
 * must never adopt or delete. A slug is [a-z0-9-], nothing a regex reads specially.
 */
const MINE = new RegExp('^' + PREFIX + '[0-9a-f]{8}$');
/** How long a page may take before the cached copy is shown instead. */
const NETWORK_TIMEOUT = 3000;

/** The cache this worker fills and reads; found again after the browser restarts the worker. */
let cacheName = null;

/** Whether this worker has asked the server for the list version yet (once per lifetime). */
let versionChecked = false;

/**
 * How many purges this worker has run. A page or fragment whose request started before a
 * purge was rendered for the identity before it, so every HTML write captures the count
 * when its request starts and is dropped if a purge has happened since (unpurged()). One
 * case stays open: a login during the very first install is a POST the not-yet-active
 * worker never sees, so nothing is purged and install stores what it fetched; the next
 * login or logout purges and refills, which repairs it.
 */
let purges = 0;

/** Whether no purge has run since `generation` was read off `purges`. */
function unpurged(generation) {
	return generation === purges;
}

/**
 * The newest of this event's caches that is complete, or null. fill() writes the stored
 * list last, so a cache without it is still filling — or was abandoned when the browser
 * killed a worker mid-fill — and must never be adopted.
 */
function newestComplete() {
	return caches.keys().then(keys => {
		// keys() lists caches in creation order: newest first means from the end
		const mine = keys.filter(key => MINE.test(key)).reverse();
		return mine.reduce((found, key) => found.then(name => name || caches.open(key)
			.then(cache => cache.match(SCOPE + 'precache.json'))
			.then(stored => (stored ? key : null))), Promise.resolve(null));
	});
}

function currentCache() {
	if (cacheName !== null) {
		return caches.open(cacheName);
	}
	return newestComplete().then(name => {
		if (name === null) {
			return null;
		}
		cacheName = name;
		return caches.open(cacheName);
	});
}

function match(key) {
	return currentCache().then(cache => (cache ? cache.match(key, {ignoreVary: true}) : undefined));
}

function put(key, response) {
	return currentCache().then(cache => (cache ? cache.put(key, response) : undefined));
}

/** put() for a page or fragment whose request started at purge count `generation`. */
function putHtml(generation, key, response) {
	return currentCache().then(cache => (cache && unpurged(generation) ? cache.put(key, response) : undefined));
}

/** Only a whole, same-origin, unredirected answer is worth keeping. */
function storable(response) {
	return response.ok && response.type === 'basic' && !response.redirected;
}

/**
 * The full document and the X-Screen fragment share a URL and differ only by a request
 * header, and the Cache API keys on the URL. So the worker never uses the request as the
 * key: the fragment's key carries a query of its own. The query exists in the cache only;
 * no page links to it, and app.js's "a URL with a query is not a screen" stays true.
 */
function cacheKey(request) {
	const url = new URL(request.url);
	if (request.headers.get('X-Screen') === '1') url.searchParams.set('x-screen', '1');
	return new Request(url.href);   // key only — the network request is the original
}

/** A screen's page and its fragment, fetched with the reader's cookie and stored under their keys. */
function storeBoth(cache, url) {
	const generation = purges;
	const requests = [new Request(url), new Request(url, {headers: {'X-Screen': '1'}})];
	return Promise.all(requests.map(request => fetch(request).then(response => {
		if (!storable(response)) {
			throw new Error(request.url + ' answered ' + response.status);
		}
		// rendered for the identity before a purge: dropped, the next refill() fetches it again
		return unpurged(generation) ? cache.put(cacheKey(request), response) : null;
	})));
}

/** Two copies of one screen, compared without the attributes that change on every request. */
function sameScreen(a, b) {
	const strip = html => html.replace(/ data-fetched-at="[^"]*"| data-stale="1"/g, '');
	return strip(a) === strip(b);
}

function tell(clientId, message) {
	if (!clientId) {
		return Promise.resolve();
	}
	return self.clients.get(clientId).then(client => {
		if (client) {
			client.postMessage(message);
		}
	});
}

/** The server's precache list; `cache` is the fetch's cache mode. */
function fetchList(cache) {
	return fetch(SCOPE + 'precache.json', {cache}).then(response => {
		if (!response.ok) {
			throw new Error('precache.json answered ' + response.status);
		}
		return response.json();
	});
}

/**
 * Fills the cache `name` from `list`: every document (page and fragment) and every asset,
 * or the fill fails and the half-filled cache is deleted. The stored list goes in only
 * once they are all there — it is the completion marker newestComplete() looks for, and
 * what refill() later reads. Optional entries follow; they may fail.
 */
function fill(name, list) {
	return caches.open(name).then(cache => cache.match(SCOPE + 'precache.json').then(complete => {
		// Same version, same content: a cache that already holds its list is complete, and it
		// may be the one the active worker serves (a new sw.js, unchanged assets), so it is
		// neither refetched nor — on a failed request — deleted.
		if (complete) {
			return null;
		}
		return Promise.all([
			...list.documents.map(url => storeBoth(cache, url)),
			cache.addAll(list.assets),
		])
			// written last: a cache holding its list is a complete one
			.then(() => cache.put(SCOPE + 'precache.json', new Response(JSON.stringify(list), {headers: {'Content-Type': 'application/json'}})))
			// one by one, and a failure ignored: a large PDF must not hold the shell hostage
			.then(() => list.optional.reduce((done, url) => done.then(() => fetch(url)
				.then(response => (storable(response) ? cache.put(cacheKey(new Request(url)), response) : null))
				.catch(() => null)), Promise.resolve()))
			// a cache another fill of this version completed meanwhile is that fill's good copy
			// — possibly the only one left — so only a cache still without its marker goes
			.catch(e => cache.match(SCOPE + 'precache.json')
				.then(marker => (marker ? null : caches.delete(name)))
				.then(() => { throw e; }));
	}));
}

/** Whether `name` still exists and holds its completion marker, read through a fresh handle. */
function isComplete(name) {
	return caches.has(name).then(exists => (exists
		? caches.open(name).then(cache => cache.match(SCOPE + 'precache.json')).then(marker => Boolean(marker))
		: false));
}

/**
 * Makes `name` the cache this worker uses and drops every other cache of this event — only
 * once `name` is confirmed to exist and be complete. Two fills of one version (a new worker's
 * install and an old worker's checkVersion()) could delete it under the other's handle; then
 * adopting it would have deleted the last good copy too, /offline included. Resolves true
 * when it adopted.
 */
function adopt(name) {
	if (name === null) {
		// nothing complete was found: that is no reason to delete what is there, which may
		// be a cache another worker is still filling
		return Promise.resolve(false);
	}
	return isComplete(name).then(complete => {
		if (!complete) {
			return false;
		}
		cacheName = name;
		return caches.keys()
			.then(keys => Promise.all(keys.filter(key => MINE.test(key) && key !== cacheName).map(key => caches.delete(key))))
			.then(() => true);
	});
}

/** Informational: nothing reloads, a reader in the middle of the timeline is not interrupted. */
function announce() {
	return self.clients.matchAll({type: 'window'})
		.then(list => list.forEach(client => client.postMessage({type: 'sw-activated', version: cacheName && cacheName.slice(PREFIX.length)})));
}

/**
 * Whether a newer worker has taken over from this one. A replaced worker's late check must
 * neither fill nor adopt: it would delete the new worker's cache. self.serviceWorker is
 * missing in older engines, and there the check proceeds.
 */
function superseded() {
	return Boolean(self.serviceWorker && self.registration.active !== self.serviceWorker);
}

/**
 * A deploy changes the list's version but not this file, so the browser never installs
 * again; this does what install would. Once per worker lifetime, after a page came from
 * the network: a different version on the server is filled into a cache of its own,
 * adopted, and announced. Any failure leaves the current cache as it is.
 */
function checkVersion() {
	if (versionChecked) {
		return Promise.resolve();
	}
	// a worker on its way in fills this version itself; two fills of one cache is how the
	// offline copy was lost. Not marked as checked: the next page may try again.
	if (self.registration.installing || self.registration.waiting) {
		return Promise.resolve();
	}
	versionChecked = true;
	return Promise.all([
		// past the HTTP cache: a conditional request, 304 when nothing changed
		fetchList('no-cache'),
		currentCache().then(cache => cache && cache.match(SCOPE + 'precache.json')).then(stored => (stored ? stored.json() : null)),
	])
		.then(([fresh, stored]) => {
			if (stored !== null && stored.version === fresh.version) {
				return null;
			}
			const name = PREFIX + fresh.version;
			if (superseded()) {
				return null;
			}
			return fill(name, fresh).then(() => (superseded() ? null : adopt(name).then(adopted => (adopted ? announce() : null))));
		})
		.catch(() => null);
}

// --- install and activate ---------------------------------------------------

self.addEventListener('install', event => {
	// a failure fails the install; the browser tries again on the next page
	event.waitUntil(fetchList('no-store')
		.then(list => fill(PREFIX + list.version, list)
			.then(() => isComplete(PREFIX + list.version))
			.then(complete => {
				if (!complete) {
					// deleted under this fill by another one: fail, the browser retries later
					throw new Error('the precache was lost while it filled');
				}
				cacheName = PREFIX + list.version;
			}))
		.then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
	event.waitUntil((cacheName !== null ? Promise.resolve(cacheName) : newestComplete())
		.then(name => adopt(name))
		.then(adopted => {
			if (!adopted) {
				// the name install filled is gone or incomplete: currentCache() must look for
				// the newest complete cache rather than open an empty one under that name
				cacheName = null;
			}
		})
		.then(() => self.clients.claim())
		.then(announce));
});

// --- fetch -------------------------------------------------------------------

self.addEventListener('fetch', event => {
	const request = event.request;
	const url = new URL(request.url);
	if (url.origin !== self.location.origin) {
		return;   // the map's iframe: the browser's business
	}
	const inScope = url.pathname.startsWith(BASE);

	if (request.method !== 'GET') {
		// A login or a logout. Whatever is cached was rendered for the identity before it,
		// so every page and fragment goes once the server has answered and before that
		// answer (and the redirect's GET) reaches the page. A POST that never gets an
		// answer — a login tapped with no signal — changed nothing and purges nothing. A push
		// subscribe or unsubscribe changes no rendered HTML (the toggle's state lives in the
		// browser's PushManager), and purging there would leave the offline copy empty,
		// /offline included, until the next full navigation, because refill() runs only
		// from page().
		if (inScope && !url.pathname.startsWith(BASE + 'admin/') && !url.pathname.startsWith(BASE + 'push/')) {
			// …and one that never reaches the server gets the offline page as a 503, not the
			// browser's own error page — an installed iOS app has no Back button out of that
			event.respondWith(fetch(request).then(response => purgeHtml().catch(() => null).then(() => response), () => offlineAnswer()));
		}
		return;
	}

	if (inScope) {
		if (url.pathname.startsWith(BASE + 'admin/') || url.pathname.startsWith(BASE + 'push/') || url.pathname === BASE + 'precache.json') {
			return;
		}
		if (request.mode === 'navigate') {
			event.respondWith(page(event));
		} else if (request.headers.get('X-Screen') === '1') {
			event.respondWith(request.cache === 'no-cache' ? fragmentFromNetwork(event) : fragmentFromCache(event));
		}
		return;
	}

	// the fonts and the icons never change in place: a changed file gets a new name
	if ((url.searchParams.has('v') && /^\/[a-z]+\.(css|js)$/.test(url.pathname)) || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/vendor/')) {
		event.respondWith(cacheFirst(event));
	} else if (url.pathname.startsWith('/events/' + SLUG + '/')) {
		event.respondWith(staleWhileRevalidate(event));
	}
	// anything else — /health, another event's files — is the browser's
});

/**
 * Every page and fragment — everything stored as text/html — out of every cache of this
 * event: a fill still running holds pages of the identity before, too.
 */
function purgeHtml() {
	purges += 1;   // first, synchronously: a write already on its way is now stale
	return caches.keys().then(keys => Promise.all(keys.filter(key => MINE.test(key)).map(key => caches.open(key)
		.then(cache => cache.keys().then(requests => Promise.all(requests.map(request => cache.match(request)
			.then(response => (response && (response.headers.get('Content-Type') || '').startsWith('text/html') ? cache.delete(request) : null)))))))));
}

/** The TIE code a full page was rendered for (its push-identity meta), '' logged out. */
function identityOf(html) {
	const found = html.match(/<meta name="push-identity" content="([^"]*)">/);
	return found ? found[1] : null;
}

/**
 * A page fresh from the network says who the cookie belongs to now. Any stored page
 * rendered for somebody else means a login or logout this worker never saw — one posted
 * while it was still installing, before it controlled the page — and every stored page
 * and fragment goes, as the purge on write would have done; refill() then puts them back
 * for the identity in the cookie. Without this, a refresh that outlasts NETWORK_TIMEOUT
 * served the copy from before the login, and the reader looked logged out.
 */
function reconcile(key, html) {
	const who = identityOf(html);
	if (who === null) {
		return Promise.resolve();
	}
	return currentCache().then(cache => cache && cache.keys()
		.then(requests => Promise.all(requests
			.filter(request => request.url !== key.url && !new URL(request.url).searchParams.has('x-screen'))
			.map(request => cache.match(request).then(response => (response && (response.headers.get('Content-Type') || '').startsWith('text/html')
				? response.text().then(identityOf)
				: null)))))
		.then(identities => (identities.some(other => other !== null && other !== who) ? purgeHtml() : null)));
}

/**
 * After a purge the next page that arrives from the network puts back every precached
 * document that is missing, rendered for the identity now in the cookie. Without it a
 * reader who logs in at home and opens only their profile would find no Program screen
 * the next time the signal is gone.
 */
function refill() {
	return currentCache().then(cache => cache && cache.match(SCOPE + 'precache.json')
		.then(stored => (stored ? stored.json() : null))
		.then(list => list && Promise.all(list.documents.map(url => Promise.all([
			cache.match(cacheKey(new Request(url)), {ignoreVary: true}),
			cache.match(cacheKey(new Request(url, {headers: {'X-Screen': '1'}})), {ignoreVary: true}),
		]).then(([full, fragment]) => (full && fragment ? null : storeBoth(cache, url).catch(() => null)))))));
}

/**
 * What a login or a logout that never reached the server answers with. Inline rather than
 * the cached /offline, which says "this page is not saved yet" — not what happened, and a
 * reader who tapped "Přihlásit" deserves to know the tap did nothing. Served by the worker,
 * so it stands on its own: no stylesheet, no script, and the inline style the CSP allows.
 */
const OFFLINE_WRITE = '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
	+ '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title></head>'
	+ '<body style="font-family: system-ui, sans-serif; line-height: 1.5; max-width: 28rem; margin: 0 auto; padding: 2rem 1rem;">'
	+ '<h1 style="font-size: 1.5rem;">Jsi offline</h1>'
	+ '<p>Přihlášení potřebuje signál. Nic se nezměnilo — zkus to znovu, až budeš online.</p>'
	+ '<p><a href="' + BASE + '">Zpět na úvod</a></p></body></html>';

/** A login or logout tapped with no signal: the page above, as a 503. */
function offlineAnswer() {
	return new Response(OFFLINE_WRITE, {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8'}});
}

/**
 * A page carries the identity — the app bar, the registered marks — so it comes from the
 * network whenever the network answers: yesterday's login state shown instantly and fixed
 * later is a lie the reader notices. A slow network (3 s) or none at all gets the cached
 * copy, and a page never cached gets the offline page. A server error (5xx) gets the
 * cached copy too when there is one; a 4xx is an answer and passes through.
 */
function page(event) {
	const request = event.request;
	const key = cacheKey(request);
	const generation = purges;
	const fallback = () => match(key)
		.then(hit => hit || match(SCOPE + 'offline'))
		.then(hit => hit || Response.error());
	if (self.navigator.onLine === false) {
		return fallback();
	}
	const network = fetch(request).then(response => ({
		response,
		stored: storable(response)
			? Promise.all([putHtml(generation, key, response.clone()), response.clone().text()])
				.then(([, html]) => reconcile(key, html))
				.then(refill)
			: Promise.resolve(),
	}));
	event.waitUntil(network
		.then(result => result.stored.catch(() => null).then(() => (storable(result.response) ? checkVersion() : null)))
		.catch(() => null));
	return new Promise(resolve => {
		let done = false;
		const finish = value => {
			if (!done) {
				done = true;
				resolve(value);
			}
		};
		const timer = setTimeout(() => match(key).then(hit => {
			if (hit) {
				finish(hit);
			}
		}), NETWORK_TIMEOUT);
		network.then(result => {
			clearTimeout(timer);
			if (result.response.status >= 500) {
				// the server is up but failing — php-fpm down, nginx's gateway error, an
				// overload: a saved copy beats the error page. Without one the error page is
				// the truth, so it passes through rather than /offline.
				match(key).then(hit => finish(hit || result.response), () => finish(result.response));
				return;
			}
			finish(result.response);
		}, () => {
			clearTimeout(timer);
			finish(fallback());
		});
	});
}

/**
 * app.js's first fetch of a screen (load()): the cached copy at once, the network behind
 * it. A copy that turns out to differ from the server's is reported to the page
 * (screen-updated), which revalidates it — one navigation is one fetch here and one read
 * in the page, never two fetches.
 */
function fragmentFromCache(event) {
	const request = event.request;
	const key = cacheKey(request);
	const generation = purges;
	return match(key).then(hit => {
		const before = hit ? hit.clone() : null;
		const network = fetch(request).then(response => {
			if (!storable(response)) {
				return {response, stored: Promise.resolve()};
			}
			const copy = response.clone();
			const stored = Promise.all([before ? before.text() : null, copy.clone().text()])
				.then(([old, fresh]) => putHtml(generation, key, copy).then(() => {
					if (old !== null && !sameScreen(old, fresh)) {
						return tell(event.clientId, {type: 'screen-updated', path: new URL(request.url).pathname});
					}
					return null;
				}));
			return {response, stored};
		});
		event.waitUntil(network.then(result => result.stored).catch(() => null));
		return hit || network.then(result => result.response);
	});
}

/** app.js's background revalidation (cache: 'no-cache'): the network, the cache only when there is none. */
function fragmentFromNetwork(event) {
	const request = event.request;
	const key = cacheKey(request);
	const generation = purges;
	return fetch(request).then(response => {
		if (storable(response)) {
			event.waitUntil(putHtml(generation, key, response.clone()));
		}
		return response;
	}, () => match(key).then(hit => (hit ? fromCache(hit) : Response.error())));
}

/** A cached copy handed to a revalidation, marked so the loader does not take it for news (www/app.js). */
function fromCache(response) {
	const headers = new Headers(response.headers);
	headers.set('X-From-Cache', '1');
	return new Response(response.body, {status: response.status, statusText: response.statusText, headers});
}

/**
 * A content-hashed asset, or a file under /fonts/ or /vendor/: its URL changes when its
 * bytes do, so a cached copy is always right.
 */
function cacheFirst(event) {
	return match(event.request).then(hit => hit || fetch(event.request).then(response => {
		if (storable(response)) {
			event.waitUntil(put(event.request, response.clone()));
		}
		return response;
	}));
}

/** An event file without a version (a logo replaced in place): the copy now, the new one next time. */
function staleWhileRevalidate(event) {
	const request = event.request;
	// nginx and .htaccess give the extension-matched files under /events/<slug>/ (png, svg,
	// jpg, webp, ico, woff2, …) a year of immutable — site.webmanifest is not among them —
	// so a plain fetch would read the browser's HTTP cache and a replaced logo would never
	// reach a returning visitor; no-cache forces a conditional request, answered 304 when
	// nothing changed.
	const network = fetch(request, {cache: 'no-cache'}).then(response => {
		if (storable(response)) {
			return put(request, response.clone()).then(() => response);
		}
		return response;
	});
	event.waitUntil(network.catch(() => null));
	return match(request).then(hit => hit || network);
}

// --- push --------------------------------------------------------------------

self.addEventListener('push', event => {
	// json() throws synchronously on a payload that is not JSON — before waitUntil,
	// so the push would be dropped rather than shown. Anything unparseable is still
	// worth telling the reader about, as plain text under the default title.
	let data = {title: 'Novinka', body: ''};
	if (event.data) {
		try {
			const parsed = event.data.json();
			data = parsed && typeof parsed === 'object' ? parsed : {title: 'Novinka', body: event.data.text()};
		} catch (e) {
			data = {title: 'Novinka', body: event.data.text()};
		}
	}
	event.waitUntil(self.registration.showNotification(data.title || 'Novinka', {
		body: data.body || '',
		icon: data.icon || undefined,
		data: {url: typeof data.url === 'string' ? data.url : null},
	})
		// the open app refreshes Novinky — and the Program screen for a programme message —
		// now, rather than whenever the reader next opens the tab
		.then(() => self.clients.matchAll({type: 'window', includeUncontrolled: true}))
		.then(list => list
			.filter(client => client.url.startsWith(SCOPE))
			.forEach(client => client.postMessage({type: 'news-updated', programme: typeof data.programme === 'number' ? data.programme : null}))));
});

// The url comes from the payload, so it is checked rather than trusted: only a page inside
// this worker's own event opens; anything else falls back to the event's start. An open
// window of the event is reused — focused, then navigated — instead of opening another.
self.addEventListener('notificationclick', event => {
	event.notification.close();
	const scope = self.registration.scope;
	let target = scope;
	const wanted = event.notification.data && event.notification.data.url;
	if (typeof wanted === 'string') {
		try {
			const url = new URL(wanted, scope);
			if (url.href.startsWith(scope)) {
				target = url.href;
			}
		} catch (e) {
			// not a URL: the scope it is
		}
	}
	event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(list => {
		const open = list.find(client => client.url.startsWith(scope));
		if (!open) {
			return clients.openWindow(target);
		}
		return open.focus()
			.then(client => client.navigate(target))
			.catch(() => clients.openWindow(target));
	}));
});
