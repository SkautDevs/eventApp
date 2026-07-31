const urlB64ToUint8Array = base64String => {
	const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
	const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
	const rawData = atob(base64);
	return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
};

window.enablePush = async () => {
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		alert('Tento prohlížeč nepodporuje notifikace.');
		return;
	}
	const permission = await Notification.requestPermission();
	if (permission !== 'granted') {
		return;
	}
	const vapidKey = document.querySelector('meta[name="vapid-public-key"]').content;
	const registration = await navigator.serviceWorker.register('sw.js');
	const subscription = await registration.pushManager.subscribe({
		userVisibleOnly: true,
		applicationServerKey: urlB64ToUint8Array(vapidKey),
	});
	await fetch('push/subscribe', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify(subscription.toJSON()),
	});
	document.querySelectorAll('.push-enable').forEach(el => el.classList.add('hide'));
};
