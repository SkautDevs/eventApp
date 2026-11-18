import {ios, standalone} from '/js/shell/install.js';
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
