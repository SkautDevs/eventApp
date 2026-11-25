import {landingUrl} from '/js/profile/pairing.js';
import {assert, test} from './harness.js';

const base = 'http://x/k/';
test("landingUrl follows the server's next inside the event", () => {
	assert.equal(landingUrl({status: 'approved', next: 'programy/moje'}, 'http://x', base), 'http://x/k/programy/moje');
	assert.equal(landingUrl({status: 'approved', next: 'programy#section-1-program-1'}, 'http://x', base), 'http://x/k/programy#section-1-program-1');
});
test('landingUrl falls back to Profil without a usable next', () => {
	assert.equal(landingUrl({status: 'approved'}, 'http://x', base), 'http://x/k/profil');
	assert.equal(landingUrl(null, 'http://x', base), 'http://x/k/profil');
	assert.equal(landingUrl({next: 5}, 'http://x', base), 'http://x/k/profil');
});
test('landingUrl never leaves the origin (Review Focus 2)', () => {
	assert.equal(landingUrl({next: 'https://evil.example/'}, 'http://x', base), 'http://x/k/profil');
	assert.equal(landingUrl({next: '//evil.example/x'}, 'http://x', base), 'http://x/k/profil');
	assert.equal(landingUrl({next: 'javascript:alert(1)'}, 'http://x', base), 'http://x/k/profil');
	// a backslash after the slash is a second slash to the URL parser: another host
	assert.equal(landingUrl({next: '/\\evil.example/x'}, 'http://x', base), 'http://x/k/profil');
});
