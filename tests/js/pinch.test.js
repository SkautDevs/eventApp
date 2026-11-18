import {span} from '/js/ui/pinch.js';
import {assert, test} from './harness.js';

test('span is the distance and the midpoint of two fingers', () => {
	assert.deepEqual(span({x: 0, y: 0}, {x: 30, y: 40}), {dist: 50, midX: 15, midY: 20});
	assert.deepEqual(span({x: 10, y: 10}, {x: 10, y: 10}), {dist: 0, midX: 10, midY: 10});
});
