import {classifyFailure, urlB64ToUint8Array} from '/js/push/index.js';
import {assert, test} from './harness.js';

test('urlB64ToUint8Array decodes URL-safe base64 without padding', () => {
	assert.deepEqual(Array.from(urlB64ToUint8Array('AQID')), [1, 2, 3]);
	assert.deepEqual(Array.from(urlB64ToUint8Array('AQ')), [1]);
	// - and _ stand for + and /
	assert.deepEqual(Array.from(urlB64ToUint8Array('-_-_')), [0xfb, 0xff, 0xbf]);
});

test('urlB64ToUint8Array gives a P-256 public key its 65 bytes', () => {
	const key = urlB64ToUint8Array('BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U');
	assert.equal(key.length, 65);
	assert.equal(key[0], 0x04);
});

const none = {rejected: false, tooMany: false, invalidKey: false};

test('classifyFailure: 502 subscription-rejected is a rejection', () => {
	assert.deepEqual(classifyFailure(502, {error: 'subscription-rejected'}), {...none, rejected: true});
	// a 502 without that body proves nothing about the subscription
	assert.deepEqual(classifyFailure(502, {}), none);
});

test('classifyFailure: 429 is too many', () => {
	assert.deepEqual(classifyFailure(429, {}), {...none, tooMany: true});
});

test('classifyFailure: 400 invalid-key is an invalid key', () => {
	assert.deepEqual(classifyFailure(400, {error: 'invalid-key'}), {...none, invalidKey: true});
	assert.deepEqual(classifyFailure(400, {error: 'something-else'}), none);
});

test('classifyFailure: anything else is none of the three', () => {
	assert.deepEqual(classifyFailure(500, {}), none);
	assert.deepEqual(classifyFailure(201, {saved: false}), none);
	assert.deepEqual(classifyFailure(0, {error: 'subscription-rejected'}), none);
});
