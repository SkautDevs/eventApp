import {emitScreenMorphed} from '/js/events.js';
import {centredEmbed, centreScroll, PLACE_ZOOM, placeFromSearch, setupPlace} from '/js/map/place.js';
import {clampZoom, planScroll} from '/js/map/plan.js';
import {mapViewFromPath, mapViewPath, openingView, setupViews} from '/js/map/views.js';
import {assert, test} from './harness.js';

test('clampZoom keeps the plan between 1× and 6× its box', () => {
	assert.equal(clampZoom(0.5), 1);
	assert.equal(clampZoom(9), 6);
	assert.equal(clampZoom(2.5), 2.5);
});

test('planScroll keeps the anchor where it is', () => {
	assert.deepEqual(planScroll(0, 0, 100, 50, 1, 1.4), {left: 40, top: 20});
});

test('planScroll never scrolls past the start', () => {
	assert.deepEqual(planScroll(0, 0, 100, 50, 1.4, 1), {left: 0, top: 0});
});

test("openingView is the plan offline and the path's view otherwise", () => {
	assert.equal(openingView(false, 'google'), 'plan');
	assert.equal(openingView(true, 'plan'), 'plan');
	assert.equal(openingView(true, 'google'), 'google');
	assert.equal(openingView(true, null), 'google');
});

test('mapViewFromPath reads the two addresses', () => {
	assert.equal(mapViewFromPath('/o/mapa', '/o/mapa'), 'google');
	assert.equal(mapViewFromPath('/o/mapa/plan', '/o/mapa'), 'plan');
	assert.equal(mapViewFromPath('/o/informace', '/o/mapa'), null);
});

test('mapViewPath is the inverse of mapViewFromPath', () => {
	assert.equal(mapViewPath('google', '/o/mapa'), '/o/mapa');
	assert.equal(mapViewPath('plan', '/o/mapa'), '/o/mapa/plan');
});

test('a pill is a link: a plain click or Space switches the view, a modified click is left to the browser', () => {
	const host = document.createElement('div');
	host.innerHTML =
		'<div data-map-root data-view="google">' +
		'<div role="tablist"><a role="tab" href="#map-test-google" data-map-view="google" aria-current="page">Mapa</a>' +
		'<a role="tab" href="#map-test-plan" data-map-view="plan">Plán</a></div></div>';
	document.body.appendChild(host);
	// after the root's listener: records what it left, then keeps the test page where it is
	const prevented = [];
	const record = event => {
		prevented.push(event.defaultPrevented);
		event.preventDefault();
	};
	document.addEventListener('click', record);
	try {
		const root = host.querySelector('[data-map-root]');
		setupViews(root, {apply() {}, zoom: () => 1});
		const pills = Array.from(root.querySelectorAll('[role="tab"]'));
		// offline the wiring opens on the plan, so the test starts from whichever is shown
		const from = root.dataset.view;
		const [shown, other] = from === 'google' ? pills : pills.reverse();
		const address = location.href;

		other.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true, ctrlKey: true}));
		assert.equal(prevented.pop(), false);
		assert.equal(root.dataset.view, from);

		other.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
		assert.equal(prevented.pop(), true);
		assert.equal(root.dataset.view, other.dataset.mapView);
		assert.equal(other.getAttribute('aria-selected'), 'true');
		assert.equal(other.getAttribute('aria-current'), 'page');
		assert.equal(shown.getAttribute('aria-selected'), 'false');
		assert.equal(shown.hasAttribute('aria-current'), false);
		// a root in no screen has no address of its own to push, and the link was not followed
		assert.equal(location.href, address);

		const space = new KeyboardEvent('keydown', {key: ' ', bubbles: true, cancelable: true});
		shown.dispatchEvent(space);
		assert.equal(space.defaultPrevented, true);
		assert.equal(prevented.pop(), true);
		assert.equal(root.dataset.view, from);
		assert.equal(shown.getAttribute('aria-current'), 'page');
		assert.equal(other.hasAttribute('aria-current'), false);
	} finally {
		document.removeEventListener('click', record);
		host.remove();
	}
});

test('placeFromSearch takes only a plain positive id', () => {
	assert.equal(placeFromSearch('?misto=7'), 7);
	assert.equal(placeFromSearch('?x=1&misto=12'), 12);
	assert.equal(placeFromSearch(''), null);
	assert.equal(placeFromSearch('?misto=007'), null);
	assert.equal(placeFromSearch('?misto=abc'), null);
	assert.equal(placeFromSearch('?misto=1234567890'), null);
});

test('centredEmbed matches the server', () => {
	assert.equal(centredEmbed('https://x/embed?mid=a', '50.340847', '15.935361'), 'https://x/embed?mid=a&ll=50.340847,15.935361&z=18');
	assert.equal(centredEmbed('https://x/embed', '1.000000', '2.000000'), 'https://x/embed?ll=1.000000,2.000000&z=18');
});

test('centreScroll centres and stays inside the canvas', () => {
	assert.deepEqual(centreScroll(500, 400, 200, 100, 1000, 800), {left: 400, top: 350});
	assert.deepEqual(centreScroll(10, 10, 200, 100, 1000, 800), {left: 0, top: 0});
	assert.deepEqual(centreScroll(995, 795, 200, 100, 1000, 800), {left: 800, top: 700});
	assert.deepEqual(centreScroll(50, 50, 200, 100, 150, 80), {left: 0, top: 0});
});

test('a place opens the plan at 250 %', () => {
	assert.equal(PLACE_ZOOM, 2.5);
});

test('setupViews tells onView every view it sets, a re-assert on a morph included', () => {
	const host = document.createElement('div');
	host.innerHTML = '<div data-map-root data-view="plan"></div>';
	document.body.appendChild(host);
	try {
		const seen = [];
		setupViews(host.firstChild, {apply() {}, zoom: () => 1}, view => seen.push(view));
		assert.deepEqual(seen, ['plan']);
		emitScreenMorphed(host);
		assert.deepEqual(seen, ['plan', 'plan']);
	} finally {
		host.remove();
	}
});

test('an address of Místa without misto hides every marker and puts the iframe back on its embed', () => {
	const host = document.createElement('div');
	host.innerHTML =
		'<section data-screen="' +
		location.pathname +
		'"><div data-map-root data-view="google">' +
		'<iframe data-embed="about:blank" src="about:blank?ll=1.000000,2.000000&amp;z=18"></iframe>' +
		'<div data-plan><div data-plan-canvas><span data-place="3" hidden></span><span data-place="4"></span></div></div>' +
		'</div></section>';
	document.body.appendChild(host);
	try {
		const root = host.querySelector('[data-map-root]');
		setupPlace(root, {apply() {}, zoom: () => 1, box: () => root.querySelector('[data-plan]')}).sync();
		assert.deepEqual(
			Array.from(root.querySelectorAll('[data-place]'), marker => marker.hidden),
			[true, true],
		);
		assert.equal(root.querySelector('iframe').getAttribute('src'), 'about:blank');
	} finally {
		host.remove();
	}
});

test('a place on a plan whose image has not loaded is centred once it loads, not before', async () => {
	const host = document.createElement('div');
	host.innerHTML =
		'<section data-screen="' +
		location.pathname +
		'"><div data-map-root data-view="plan">' +
		'<div data-plan style="width: 100px; height: 100px; overflow: auto">' +
		'<div data-plan-canvas style="position: relative; width: 250px">' +
		'<img data-plan-img alt="" style="display: block; width: 100%">' +
		'<span data-place="5" style="position: absolute; left: 80%; top: 80%; width: 10px; height: 10px"></span>' +
		'</div></div></div></section>';
	document.body.appendChild(host);
	const address = location.href;
	history.replaceState(history.state, '', location.pathname + '?misto=5');
	try {
		const root = host.querySelector('[data-map-root]');
		const img = root.querySelector('[data-plan-img]');
		const box = root.querySelector('[data-plan]');
		const zooms = [];
		const place = setupPlace(root, {apply: zoom => zooms.push(zoom), restore() {}, zoom: () => 1, box: () => box});
		// an image without a src is complete, with no height: a marker's top would read 0
		assert.equal(img.complete, true);
		assert.equal(img.naturalHeight, 0);

		place.sync();
		assert.deepEqual(zooms, [PLACE_ZOOM]);
		assert.deepEqual([box.scrollLeft, box.scrollTop], [0, 0], 'no centring before the image loads');

		// registered after place.js's own listener, so it runs once that one has centred
		const loaded = new Promise((resolve, reject) => {
			img.addEventListener('load', resolve, {once: true});
			img.addEventListener('error', () => reject(new Error('the test image did not load')), {once: true});
		});
		img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
		await loaded;
		// no key was set before the load, or the load's sync would have skipped the centring
		assert.ok(box.scrollLeft > 100 && box.scrollTop > 100, 'centred on the marker: ' + box.scrollLeft + ',' + box.scrollTop);

		// the key is set now: the same place is not centred again
		box.scrollLeft = 0;
		box.scrollTop = 0;
		place.sync();
		assert.deepEqual([box.scrollLeft, box.scrollTop], [0, 0], 'centred once per place');
	} finally {
		history.replaceState(history.state, '', address);
		host.remove();
	}
});
