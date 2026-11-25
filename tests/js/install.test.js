import {android, inApp, installCase, ios, mobile, standalone} from '/js/shell/install.js';
import {assert, test} from './harness.js';

const media = matching => query => ({matches: query === matching});

test('standalone: iOS says so on navigator', () => {
	assert.equal(standalone({standalone: true}, null), true);
	assert.equal(standalone({standalone: true}, media('')), true);
});

test('standalone: elsewhere the display mode says so', () => {
	assert.equal(standalone({}, media('(display-mode: standalone)')), true);
	assert.equal(standalone({standalone: false}, media('(display-mode: browser)')), false);
});

test('standalone: no matchMedia and no flag is a browser tab', () => {
	assert.equal(standalone({}, null), false);
});

test('ios: an iPhone, an iPad or an iPod by its user agent', () => {
	assert.equal(ios({userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'}), true);
	assert.equal(ios({userAgent: 'Mozilla/5.0 (iPad; CPU OS 12_0 like Mac OS X)'}), true);
});

test('ios: iPadOS reports itself as a Mac, the touch points give it away', () => {
	assert.equal(ios({userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5}), true);
	assert.equal(ios({userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 0}), false);
});

test('ios: Android is not iOS', () => {
	assert.equal(ios({userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8)', platform: 'Linux armv8l', maxTouchPoints: 5}), false);
});

const none = {standalone: false, offer: false, ios: false, android: false, inApp: false, mobile: false};

test('installCase: an installed app shows nothing, whatever else holds', () => {
	assert.equal(installCase({...none, standalone: true, offer: true, ios: true, inApp: true, mobile: true}), 'none');
});

test('installCase: a kept offer wins over every manual step', () => {
	assert.equal(installCase({...none, offer: true}), 'offer');
	assert.equal(installCase({...none, offer: true, android: true, mobile: true}), 'offer');
});

test('installCase: an in-app browser on a phone, before the platform', () => {
	assert.equal(installCase({...none, inApp: true, ios: true, mobile: true}), 'inApp');
	assert.equal(installCase({...none, inApp: true, android: true, mobile: true}), 'inApp');
	assert.equal(installCase({...none, inApp: true}), 'none');
});

test('installCase: iOS, then Android, then nothing on a computer', () => {
	assert.equal(installCase({...none, ios: true, mobile: true}), 'ios');
	assert.equal(installCase({...none, android: true, mobile: true}), 'android');
	assert.equal(installCase(none), 'none');
});

test('inApp: each marker of the spec list, and not a plain browser', () => {
	const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
	const pixel =
		'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UQ1A.240205.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.230 Mobile Safari/537.36';
	for (const ua of [
		iphone + ' [FBAN/FBIOS;FBAV/450.0.0.38.108;FBBV/565075686]',
		pixel + ' FBAV/450.0.0.43.109',
		pixel + ' [FB_IAB/FB4A;FBAV/450.0.0.43.109;]',
		iphone + ' Instagram 312.0.0.0.0 (iPhone15,2; iOS 17_0; cs_CZ)',
		pixel + ' Messenger',
		iphone + ' Safari Line/13.1.0',
		iphone + ' LinkedInApp/9.29.6',
	]) {
		assert.equal(inApp({userAgent: ua}), true, ua);
	}
	assert.equal(inApp({userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'}), false);
});

test('android and mobile', () => {
	assert.equal(android({userAgent: 'Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0'}), true);
	assert.equal(mobile({userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8)'}), true);
	assert.equal(mobile({userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'}), true);
	assert.equal(mobile({userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5}), true);
	assert.equal(mobile({userAgent: 'Mozilla/5.0 (X11; Linux x86_64)', platform: 'Linux x86_64', maxTouchPoints: 0}), false);
});
