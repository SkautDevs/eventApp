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
