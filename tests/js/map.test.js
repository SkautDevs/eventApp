import {clampZoom, planScroll} from '/js/map/plan.js';
import {mapViewFromPath, mapViewPath, openingView} from '/js/map/views.js';
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
