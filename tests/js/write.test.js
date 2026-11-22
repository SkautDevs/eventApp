import {nextOf, reloadState, sameDocument} from '/js/shell/write.js';
import {assert, test} from './harness.js';

test('nextOf takes a same-origin next and nothing else', () => {
	assert.equal(nextOf({next: '/k/programy/moje#section-1-program-5'}, 'http://x', 'http://x/k/'), 'http://x/k/programy/moje#section-1-program-5');
	assert.equal(nextOf({next: 'https://evil.example/'}, 'http://x', 'http://x/k/'), null);
	assert.equal(nextOf({next: 5}, 'http://x', 'http://x/k/'), null);
	assert.equal(nextOf(null, 'http://x', 'http://x/k/'), null);
});

test('sameDocument is a hash-only difference with a hash in next', () => {
	assert.ok(sameDocument('http://x/k/programy#section-1-program-1', 'http://x/k/programy#section-1-program-1'));
	assert.ok(sameDocument('http://x/k/programy/moje#offline', 'http://x/k/programy/moje#section-1-program-5'));
	assert.ok(!sameDocument('http://x/k/profil', 'http://x/k/profil'));
	assert.ok(!sameDocument('http://x/k/programy/moje#a', 'http://x/k/programy#a'));
});

test('reloadState keeps the sheet entry for a programme next and drops it otherwise', () => {
	const sheet = {screen: '/k/programy', pgOverlay: true};
	assert.deepEqual(reloadState('http://x/k/programy#section-1-program-1', sheet), sheet);
	assert.deepEqual(reloadState('http://x/k/programy/moje#offline', sheet), {screen: '/k/programy'});
	assert.deepEqual(reloadState('http://x/k/profil#offline', {screen: '/k/profil'}), {screen: '/k/profil'});
	assert.equal(reloadState('http://x/k/profil#offline', null), null);
});
