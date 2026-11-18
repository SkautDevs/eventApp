/**
 * The offline worker's registration, on every page, not only when the notification button
 * is tapped: without a worker nothing opens offline and Chrome does not offer to install
 * the app. register() is idempotent, so push/index.js registering the same script again changes
 * nothing. The worker's messages are the loader's (loader/loader.js) to listen to.
 */

const meta = name => {
	const el = document.querySelector('meta[name="' + name + '"]');
	return el ? el.content : '';
};

if ('serviceWorker' in navigator && meta('event-base') !== '') {
	navigator.serviceWorker.register('sw.js', {scope: meta('event-base')}).catch(() => {
		// an insecure origin, a browser that refuses: the app works online as before
	});
}
