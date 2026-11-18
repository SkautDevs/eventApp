import {assert, test} from './harness.js';

test('the assertions pass on what they accept', () => {
	assert.equal(1, 1);
	assert.deepEqual({a: [1, {b: 2}]}, {a: [1, {b: 2}]});
	assert.ok('yes');
	assert.throws(() => {
		throw new TypeError('nope');
	}, TypeError);
	assert.throws(() => {
		throw new Error('the reason');
	}, /reason/);
});

test('the assertions fail on what they refuse', () => {
	assert.throws(() => assert.equal(1, '1'));
	assert.throws(() => assert.deepEqual([1, 2], [2, 1]));
	assert.throws(() => assert.deepEqual({a: 1}, {a: 1, b: undefined}));
	assert.throws(() => assert.ok(0));
	assert.throws(() => assert.throws(() => {}));
});

test('an async test is awaited', async () => {
	const value = await Promise.resolve(3);
	assert.equal(value, 3);
});
