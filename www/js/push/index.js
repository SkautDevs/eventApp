/**
 * The notifications card on Domů and Profil (templates/_notifications.twig): shown once the
 * browser has said it is not subscribed, with its button and status line; the subscribe
 * call and its toast; Profil's quiet line once subscribed; and the re-sync of a
 * subscription whose paired participant changed. It still listens for DOMContentLoaded
 * itself: PushToggleTest dispatches that event again to restart it with its fakes in place.
 */

import {onScreenMorphed, onScreenShown} from '../events.js';
import {showToast} from '../shell/toast.js';

/** The bytes of a URL-safe base64 string without padding: the VAPID public key's form. */
export function urlB64ToUint8Array(value) {
	const padding = '='.repeat((4 - (value.length % 4)) % 4);
	const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
	const rawData = atob(base64);
	return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}

/** What a failed subscribe answer (its status and its JSON body, {} when none) proves. */
export function classifyFailure(status, body) {
	return {
		// 502 subscription-rejected: the push service disowned it and the server dropped
		// the row. Nothing else — no network, a 500 — proves anything about it.
		rejected: status === 502 && body.error === 'subscription-rejected',
		// 429 too-many: the address has subscribed a lot just now (a camp behind one NAT);
		// 400 invalid-key: the browser's keys are unusable and a fresh subscription is needed
		tooMany: status === 429,
		invalidKey: status === 400 && body.error === 'invalid-key',
	};
}

const meta = name => (document.querySelector('meta[name="' + name + '"]') || {}).content || '';

// The server takes the paired participant's id from the session, so re-sending the same
// subscription is how it learns about a login or logout (push-identity carries the id). The key remembers what it last heard, per event,
// so this is one POST per change rather than one per page.
const identityKey = () => 'pushIdentity:' + meta('event-base');

// Every line the opt-in can show, in one place.
const TEXT = {
	toast: 'Notifikace zapnuté, jupí!',
	toastWelcome: 'Právě ti přišla uvítací notifikace.',
	off: 'Notifikace jsou vypnuté.',
	denied: 'Notifikace máš v prohlížeči zakázané. Povol je v nastavení stránky a zkus to znovu.',
	enableFailed: 'Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.',
	tooMany: 'Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.',
	invalidKey: 'Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.',
	preparing: 'Připravuji…',
	ios: 'Na iPhonu zapneš notifikace, až si aplikaci přidáš na plochu.',
};

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window;

// Safari on iOS has PushManager only inside an app added to the home screen. iPadOS
// reports itself as a Mac; the touch points give it away.
const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/**
 * What the card shows: the card itself, its button, its status line (null: none) and
 * Profil's quiet line. iOS outside an installed app has no push at all and is told to save
 * the app first, whatever is known. Elsewhere the card waits until the browser has said
 * whether it is subscribed (known), so a subscribed reader never sees it flash; once
 * subscribed it goes for good, since notifications are turned off only in the browser's
 * own settings, and Profil says quietly that they are on.
 */
export function cardState({supported, ios, known, subscribed, status}) {
	if (!supported && ios) {
		return {card: true, button: false, status: TEXT.ios, on: false};
	}
	if (!supported || !known) {
		return {card: false, button: false, status: null, on: false};
	}
	if (subscribed) {
		return {card: false, button: false, status: null, on: true};
	}
	return {card: true, button: true, status, on: false};
}

// The card is drawn from these three by render(), so a screen the loader shows — or
// morphs back to the server's markup — later is simply drawn again. known turns true
// once detectSubscription() has answered, whatever it found.
let subscribed = false;
let status = null;
let known = false;

const render = () => {
	const state = cardState({supported: supported(), ios: isIos(), known, subscribed, status});
	document.querySelectorAll('[data-push-card]').forEach(el => {
		el.hidden = !state.card;
	});
	document.querySelectorAll('.push-enable').forEach(el => {
		el.hidden = !state.button;
	});
	document.querySelectorAll('.push-status').forEach(el => {
		// shown before it is filled, so a screen reader hears the change in the live region
		el.hidden = state.status === null;
		if (state.status !== null && el.textContent !== state.status) {
			el.textContent = state.status;
		}
	});
	document.querySelectorAll('[data-push-on]').forEach(el => {
		el.hidden = !state.on;
	});
};

const setBusy = busy =>
	document.querySelectorAll('[data-push-toggle]').forEach(el => {
		el.disabled = busy;
	});

const sendSubscription = async subscription => {
	const response = await fetch(meta('event-base') + 'push/subscribe', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		// the session cookie is what binds the subscription to the paired participant
		credentials: 'same-origin',
		body: JSON.stringify(subscription.toJSON()),
	});
	const result = await response.json().catch(() => ({}));
	if (!response.ok || result.saved !== true) {
		throw Object.assign(new Error('push subscribe failed'), classifyFailure(response.status, result));
	}
	try {
		localStorage.setItem(identityKey(), meta('push-identity'));
	} catch (_e) {
		// private mode: the next page simply sends it again
	}
	return result;
};

/** The browser subscribes only through an active worker; on a first visit it may still be installing. */
const READY_TIMEOUT = 10000;
const workerReady = () => {
	let timer = null;
	return Promise.race([
		navigator.serviceWorker.ready,
		new Promise((_resolve, reject) => {
			timer = setTimeout(() => reject(new Error('the worker did not become active')), READY_TIMEOUT);
		}),
	]).finally(() => clearTimeout(timer));
};

const enablePush = async () => {
	setBusy(true);
	try {
		const permission = await Notification.requestPermission();
		if (permission !== 'granted') {
			status = TEXT.denied;
			return;
		}
		let subscription = null;
		try {
			await navigator.serviceWorker.register('sw.js', {scope: meta('event-base')});
			status = TEXT.preparing;
			render();
			const registration = await workerReady();
			subscription = await registration.pushManager.subscribe({
				userVisibleOnly: true,
				applicationServerKey: urlB64ToUint8Array(meta('vapid-public-key')),
			});
			const result = await sendSubscription(subscription);
			subscribed = true;
			status = null;
			// welcome false: saved, but the welcome did not get through — say only what is true
			showToast(TEXT.toast, result.welcome === true ? TEXT.toastWelcome : undefined);
		} catch (e) {
			if (e.tooMany === true) {
				// The browser's subscription is fine and is kept: the next page re-sends it
				// (nothing was remembered as sent), and so does the next tap.
				status = TEXT.tooMany;
				return;
			}
			// Half a subscription is worse than none: the next page would believe it is on.
			// Dropping it here makes the next tap start over, welcome included.
			if (subscription) {
				await subscription.unsubscribe().catch(() => {});
			}
			status = e.invalidKey === true ? TEXT.invalidKey : TEXT.enableFailed;
		}
	} finally {
		setBusy(false);
		render();
	}
};

// Whether this browser is already subscribed. Whatever it finds — or if it fails — the
// card is known afterwards: shown when not subscribed, the quiet line when subscribed.
const detectSubscription = async () => {
	try {
		if (!supported() || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
			return;
		}
		const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
		if (registration && (await registration.pushManager.getSubscription())) {
			subscribed = true;
			status = null;
		}
	} finally {
		known = true;
		render();
	}
};

const syncIdentity = async () => {
	if (!supported() || !meta('vapid-public-key')) {
		return;
	}
	let last = null;
	try {
		last = localStorage.getItem(identityKey());
	} catch (_e) {
		// unreadable: treat as unknown and send
	}
	if (last === meta('push-identity')) {
		return;
	}
	const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
	const subscription = registration && (await registration.pushManager.getSubscription());
	if (!subscription) {
		return;
	}
	try {
		await sendSubscription(subscription);
	} catch (e) {
		// Only the push service's refusal proves the subscription dead; a network error
		// or a 500 proves nothing, and dropping a working one over it would be worse.
		if (e.rejected === true) {
			await subscription.unsubscribe().catch(() => {});
			try {
				localStorage.removeItem(identityKey());
			} catch (_e) {
				// private mode: nothing was stored
			}
			subscribed = false;
			status = TEXT.off;
			render();
		}
	}
};

// The homepage button has no inline handler (the CSP allows no inline script): it is bound
// here, on the first document and on every screen the loader shows or morphs later. The
// WeakSet keeps a button that is shown twice from getting two listeners.
const boundToggles = new WeakSet();
const bindPushToggles = scope => {
	(scope && scope.querySelectorAll ? scope : document).querySelectorAll('[data-push-toggle]').forEach(button => {
		if (boundToggles.has(button)) {
			return;
		}
		boundToggles.add(button);
		button.addEventListener('click', () => {
			if (!subscribed) {
				enablePush();
			}
		});
	});
};

document.addEventListener('DOMContentLoaded', () => {
	bindPushToggles(document);
	// a start (or PushToggleTest's restart) begins unknown: the card waits for the answer
	known = false;
	render();
	// one after the other: a re-sync that finds the subscription dead lets go of it,
	// and only then is the browser asked whether it is still subscribed
	syncIdentity()
		.catch(() => {})
		.then(() => detectSubscription())
		.catch(() => {});
});
// the morph writes the server's hidden back; render() puts the state back
onScreenShown(target => {
	bindPushToggles(target);
	render();
});
onScreenMorphed(target => {
	bindPushToggles(target);
	render();
});
