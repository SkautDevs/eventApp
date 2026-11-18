import {clampZoom, planScroll} from '/js/map/plan.js';
import {openingView} from '/js/map/views.js';
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

test('openingView: offline the plan opens, whatever was stored', () => {
	assert.equal(openingView(false, 'google'), 'plan');
});

test('openingView: online the stored view opens, else Google', () => {
	assert.equal(openingView(true, 'plan'), 'plan');
	assert.equal(openingView(true, 'google'), 'google');
	assert.equal(openingView(true, null), 'google');
	assert.equal(openingView(true, 'nonsense'), 'google');
});
