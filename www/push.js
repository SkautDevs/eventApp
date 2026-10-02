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

const sendSubscription = async subscription => {
	const response = await fetch(meta('event-base') + 'push/subscribe', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify(subscription.toJSON()),
	});
	if (!response.ok) {
		throw new Error('push subscribe failed');
	}
	try {
		localStorage.setItem(identityKey(), meta('push-identity'));
	} catch (e) {
		// private mode: the next page simply sends it again
	}
	return response.json().catch(() => ({}));
};

// done: the button gives way to the line; a failure keeps it, so the reader can simply try again
const showStatus = (text, done = true) => {
	document.querySelectorAll('.push-enable').forEach(el => el.classList.toggle('hide', done));
	document.querySelectorAll('.push-status').forEach(el => {
		// shown before it is filled, so a screen reader hears the change in the live region
		el.hidden = false;
		el.textContent = text;
	});
};

const setBusy = busy => document.querySelectorAll('.push-enable button').forEach(el => {
	el.disabled = busy;
});

window.enablePush = async () => {
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		alert('Tento prohlížeč nepodporuje notifikace.');
		return;
	}
	setBusy(true);
	try {
		const permission = await Notification.requestPermission();
		if (permission !== 'granted') {
			showStatus('Notifikace máš v prohlížeči zakázané. Povol je v nastavení stránky a zkus to znovu.', false);
			return;
		}
		let subscription = null;
		try {
			const registration = await navigator.serviceWorker.register('sw.js', {scope: meta('event-base')});
			subscription = await registration.pushManager.subscribe({
				userVisibleOnly: true,
				applicationServerKey: urlB64ToUint8Array(meta('vapid-public-key')),
			});
			// a 502 here means the welcome did not get through and the server has dropped the row
			const result = await sendSubscription(subscription);
			showStatus(result.welcome === true ? 'Hotovo! Právě ti přišla uvítací notifikace.' : 'Notifikace máš zapnuté, jupí!');
		} catch (e) {
			// Half a subscription is worse than none: the next page would believe it is on and
			// hide the button. Dropping it here makes the next tap start over, welcome included.
			if (subscription) {
				await subscription.unsubscribe().catch(() => {});
			}
			showStatus('Notifikace se nepodařilo zapnout. Zkontroluj připojení a zkus to znovu.', false);
		}
	} finally {
		setBusy(false);
	}
};

// A browser that is already subscribed has nothing to tap, so the button gives way to a line
// saying so — an empty spot where the button was would read as a fault.
const hideWhenSubscribed = async () => {
	if (!('serviceWorker' in navigator) || !('PushManager' in window) || Notification.permission !== 'granted') {
		return;
	}
	const registration = await navigator.serviceWorker.getRegistration(meta('event-base'));
	if (registration && await registration.pushManager.getSubscription()) {
		showStatus('Notifikace máš zapnuté, jupí!');
	}
};

const syncIdentity = async () => {
	if (!('serviceWorker' in navigator) || !('PushManager' in window) || !meta('vapid-public-key')) {
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

document.addEventListener('DOMContentLoaded', () => {
	syncIdentity().catch(() => {});
	hideWhenSubscribed().catch(() => {});
});
