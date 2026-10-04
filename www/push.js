(function () {
	'use strict';

	const urlB64ToUint8Array = base64String => {
		const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
		const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
		const rawData = atob(base64);
		return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
	};

	const meta = name => (document.querySelector('meta[name="' + name + '"]') || {}).content || '';

	// The server takes the TIE code from the session, so re-sending the same subscription is
	// how it learns about a login or logout. The key remembers what it last heard, per event,
	// so this is one POST per change rather than one per page.
	const identityKey = () => 'pushIdentity:' + meta('event-base');

	// Every line the opt-in can show, in one place.
	const TEXT = {
		enable: 'Aktivuj si notifikace o akci!',
		disable: 'Vypnout notifikace',
		welcome: 'Hotovo! Právě ti přišla uvítací notifikace.',
		on: 'Notifikace máš zapnuté.',
		off: 'Notifikace jsou vypnuté.',
		denied: 'Notifikace máš v prohlížeči zakázané. Povol je v nastavení stránky a zkus to znovu.',
		enableFailed: 'Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.',
		disableFailed: 'Notifikace se nepodařilo vypnout. Zkus to znovu.',
		ios: 'Na iPhonu si nejdřív přidej aplikaci na plochu (Sdílet → Přidat na plochu), pak zapneš notifikace.',
	};

	const supported = () => 'serviceWorker' in navigator && 'PushManager' in window;

	// Safari on iOS has PushManager only inside an app added to the home screen. iPadOS
	// reports itself as a Mac; the touch points give it away.
	const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent)
		|| (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

	// The opt-in is drawn from these two by render(), so a screen the loader shows — or
	// morphs back to the server's markup — later is simply drawn again.
	let subscribed = false;
	let status = null;

	const render = () => {
		const ok = supported();
		// without push the homepage looks like every push-less event's: no button at all
		document.querySelectorAll('.push-enable').forEach(el => {
			el.hidden = !ok;
		});
		document.querySelectorAll('[data-push-toggle]').forEach(button => {
			button.textContent = subscribed ? TEXT.disable : TEXT.enable;
			button.classList.toggle('btn-primary', !subscribed);
		});
		const text = ok ? status : (isIos() ? TEXT.ios : null);
		document.querySelectorAll('.push-status').forEach(el => {
			// shown before it is filled, so a screen reader hears the change in the live region
			el.hidden = text === null;
			if (text !== null && el.textContent !== text) {
				el.textContent = text;
			}
		});
	};

	const setBusy = busy => document.querySelectorAll('[data-push-toggle]').forEach(el => {
		el.disabled = busy;
	});

	const sendSubscription = async subscription => {
		const response = await fetch(meta('event-base') + 'push/subscribe', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			// the session cookie is what ties the subscription to the TIE code
			credentials: 'same-origin',
			body: JSON.stringify(subscription.toJSON()),
		});
		const result = await response.json().catch(() => ({}));
		// a 502 subscription-rejected: the push service disowned it and the server dropped the row
		if (!response.ok || result.saved !== true) {
			throw new Error('push subscribe failed');
		}
		try {
			localStorage.setItem(identityKey(), meta('push-identity'));
		} catch (e) {
			// private mode: the next page simply sends it again
		}
		return result;
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
				const registration = await navigator.serviceWorker.register('sw.js', {scope: meta('event-base')});
				subscription = await registration.pushManager.subscribe({
					userVisibleOnly: true,
					applicationServerKey: urlB64ToUint8Array(meta('vapid-public-key')),
				});
				const result = await sendSubscription(subscription);
				subscribed = true;
				// welcome false: saved, but the welcome did not get through — say only what is true
				status = result.welcome === true ? TEXT.welcome : TEXT.on;
			} catch (e) {
				// Half a subscription is worse than none: the next page would believe it is on.
				// Dropping it here makes the next tap start over, welcome included.
				if (subscription) {
					await subscription.unsubscribe().catch(() => {});
				}
				status = TEXT.enableFailed;
			}
		} finally {
			setBusy(false);
			render();
		}
	};

	const disablePush = async () => {
		setBusy(true);
		try {
			const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
			const subscription = registration && await registration.pushManager.getSubscription();
			if (subscription) {
				const endpoint = subscription.endpoint;
				// the browser first: once it has let go, nothing can arrive whatever the server does
				if (!await subscription.unsubscribe()) {
					throw new Error('unsubscribe refused');
				}
				// ignored when it fails: the next send gets a 410 for this endpoint and drops the row
				await fetch(meta('event-base') + 'push/unsubscribe', {
					method: 'POST',
					headers: {'Content-Type': 'application/json'},
					credentials: 'same-origin',
					body: JSON.stringify({endpoint}),
				}).catch(() => {});
			}
			subscribed = false;
			status = TEXT.off;
			// a later subscribe is then sent whatever the login state
			try {
				localStorage.removeItem(identityKey());
			} catch (e) {
				// private mode: nothing was stored
			}
		} catch (e) {
			status = TEXT.disableFailed;
		} finally {
			setBusy(false);
			render();
		}
	};

	// A browser that is already subscribed shows the off switch and says so.
	const detectSubscription = async () => {
		if (!supported() || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
			return;
		}
		const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
		if (registration && await registration.pushManager.getSubscription()) {
			subscribed = true;
			status = TEXT.on;
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
		} catch (e) {
			// unreadable: treat as unknown and send
		}
		if (last === meta('push-identity')) {
			return;
		}
		const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
		const subscription = registration && await registration.pushManager.getSubscription();
		if (subscription) {
			await sendSubscription(subscription);
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
			button.addEventListener('click', () => (subscribed ? disablePush() : enablePush()));
		});
	};

	document.addEventListener('DOMContentLoaded', () => {
		bindPushToggles(document);
		render();
		syncIdentity().catch(() => {});
		detectSubscription().catch(() => {});
	});
	// the morph writes the server's label, class and hidden back; render() puts the state back
	document.addEventListener('screen:shown', event => {
		bindPushToggles(event.target);
		render();
	});
	document.addEventListener('screen:morphed', event => {
		bindPushToggles(event.target);
		render();
	});
})();
