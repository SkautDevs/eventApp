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
	}));
});

self.addEventListener('notificationclick', event => {
	event.notification.close();
	event.waitUntil(clients.openWindow('/'));
});
