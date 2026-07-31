self.addEventListener('push', event => {
	const data = event.data ? event.data.json() : {title: 'Novinka', body: ''};
	event.waitUntil(self.registration.showNotification(data.title, {
		body: data.body,
		icon: data.icon || undefined,
	}));
});

self.addEventListener('notificationclick', event => {
	event.notification.close();
	event.waitUntil(clients.openWindow('/'));
});
