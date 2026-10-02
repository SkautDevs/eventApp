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
	}));
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
